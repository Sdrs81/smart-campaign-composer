<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { onBeforeRouteLeave, RouterLink, useRouter } from 'vue-router'

import type { Campaign, CampaignStatus } from '../api/types'
import ApproveDialog from '../components/ApproveDialog.vue'
import AudienceCard from '../components/AudienceCard.vue'
import BackLink from '../components/BackLink.vue'
import BriefStep from '../components/BriefStep.vue'
import ContentEditor from '../components/ContentEditor.vue'
import ContentStream from '../components/ContentStream.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useCampaignStore } from '../stores/campaign'

// Without an id this is the "new campaign" page.
const props = defineProps<{ id?: number }>()

const store = useCampaignStore()
const router = useRouter()
const { t } = useI18n()
const draftBrief = ref('')

// The audience can be redone until the campaign is approved (the server answers 409 afterwards).
const EDITABLE: CampaignStatus[] = ['draft', 'audience_ready', 'content_ready']

const isNew = computed(() => props.id === undefined)
const campaign = computed(() => store.current)
const canExtract = computed(
  () => isNew.value || (campaign.value !== null && EDITABLE.includes(campaign.value.status)),
)
// The text box holds the brief being written. A saved campaign puts its brief there, and the brief stays
// editable until approval: the rule is read from it again by "Re-run extraction".
watch(
  () => campaign.value?.brief,
  (saved) => {
    if (saved !== undefined) draftBrief.value = saved
  },
  { immediate: true },
)
const submitLabel = computed(() => {
  if (isNew.value) return t('brief.extract')
  return campaign.value?.segment_rule ? t('brief.rerun') : t('brief.extract')
})

async function open(): Promise<void> {
  await store.loadRules()
  if (props.id === undefined) {
    store.startNew()
    draftBrief.value = ''
  } else await store.loadCurrent(props.id)
}

async function submit(): Promise<void> {
  if (isNew.value) {
    const createdId = await store.createAndExtract(draftBrief.value)
    // Move to the campaign even when extraction failed, so "try again" works on the saved campaign.
    if (createdId !== null) await router.replace({ name: 'campaign', params: { id: createdId } })
    return
  }
  await store.extractAudience(draftBrief.value)
}

// Reported by the editor: the text fits, is saved and the server has no complaint.
const canApprove = ref(false)

async function goToDashboard(approved: Campaign): Promise<void> {
  store.current = approved
  await router.push({ name: 'campaign-dashboard', params: { id: approved.id } })
}

// New model text replaces the editor's fields, so the editor is re-created once the campaign holds it.
const contentVersion = ref(0)
async function onContentGenerated(): Promise<void> {
  await store.reloadCurrent()
  contentVersion.value++
}

onMounted(open)
watch(() => props.id, open)
onBeforeRouteLeave(() => store.leaveCurrent())
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-6">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-3">
        <BackLink />
        <h2 class="text-lg font-semibold">
          {{ isNew ? t('composer.newTitle') : t('composer.title', { id }) }}
        </h2>
      </div>
      <StatusBadge v-if="campaign" :status="campaign.status" />
    </div>

    <div aria-live="polite">
      <p v-if="store.composing && !campaign" class="text-slate-600">{{ t('composer.loading') }}</p>
    </div>

    <template v-if="store.rules && (isNew || campaign)">
      <BriefStep
        v-model="draftBrief"
        :limits="store.rules.brief"
        :busy="store.composing"
        :readonly="!canExtract"
        :can-submit="canExtract"
        :submit-label="submitLabel"
        @submit="submit"
      />
    </template>

    <div
      v-if="store.composeError"
      role="alert"
      class="space-y-2 rounded border border-red-300 bg-red-50 p-4"
    >
      <p class="font-medium text-red-900">{{ store.composeError.message }}</p>
      <ul v-if="store.composeError.problems.length > 0" class="list-disc pl-5 text-sm text-red-900">
        <li v-for="problem in store.composeError.problems" :key="problem">{{ problem }}</li>
      </ul>
      <ul
        v-if="store.composeError.unsupportedCriteria.length > 0"
        class="list-disc pl-5 text-sm text-red-900"
      >
        <li v-for="criterion in store.composeError.unsupportedCriteria" :key="criterion">
          {{ t('composer.notExpressible', { criterion }) }}
        </li>
      </ul>
      <p
        v-if="store.composeError.status === 503 || store.composeError.status === 0"
        class="text-sm text-red-900"
      >
        {{ t('composer.unreachable') }}
      </p>
    </div>

    <p v-if="campaign && !canExtract" class="text-sm text-slate-600">
      {{ t('composer.approvedLocked') }}
      <RouterLink
        :to="{ name: 'campaign-dashboard', params: { id: campaign.id } }"
        class="ml-1 text-indigo-700 underline focus:outline-2 focus:outline-indigo-600"
      >
        {{ t('composer.viewDashboard') }}
      </RouterLink>
    </p>

    <AudienceCard
      v-if="campaign?.segment_rule && store.rules"
      :campaign="campaign"
      :segment="store.rules.segment"
    />

    <!-- Not on the "new" page: it is replaced by the campaign page right after the extraction, which
         would cancel a stream started here. -->
    <ContentStream
      v-if="
        campaign &&
        !isNew &&
        (campaign.status === 'audience_ready' || campaign.status === 'content_ready')
      "
      :key="campaign.id"
      :campaign="campaign"
      :autostart="store.autoGenerateFor === campaign.id"
      @started="store.clearAutoGenerate()"
      @completed="onContentGenerated"
    />

    <ContentEditor
      v-if="campaign?.messages?.length && store.rules"
      :key="`${campaign.id}-${contentVersion}`"
      :campaign="campaign"
      :rules="store.rules"
      @saved="store.current = $event"
      @can-approve="canApprove = $event"
    />

    <ApproveDialog
      v-if="campaign?.status === 'content_ready'"
      :campaign="campaign"
      :can-approve="canApprove"
      @approved="goToDashboard"
    />
  </section>
</template>
