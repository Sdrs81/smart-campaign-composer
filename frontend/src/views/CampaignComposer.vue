<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'

import type { CampaignStatus } from '../api/types'
import AudienceCard from '../components/AudienceCard.vue'
import BriefStep from '../components/BriefStep.vue'
import ContentEditor from '../components/ContentEditor.vue'
import ContentStream from '../components/ContentStream.vue'
import StatusBadge from '../components/StatusBadge.vue'
import { useCampaignStore } from '../stores/campaign'

// Without an id this is the "new campaign" page.
const props = defineProps<{ id?: number }>()

const store = useCampaignStore()
const router = useRouter()
const draftBrief = ref('')

// The audience can be redone until the campaign is approved (the server answers 409 afterwards).
const EDITABLE: CampaignStatus[] = ['draft', 'audience_ready', 'content_ready']

const isNew = computed(() => props.id === undefined)
const campaign = computed(() => store.current)
const canExtract = computed(
  () => isNew.value || (campaign.value !== null && EDITABLE.includes(campaign.value.status)),
)
const briefText = computed({
  get: () => (isNew.value ? draftBrief.value : (campaign.value?.brief ?? '')),
  set: (value: string) => {
    draftBrief.value = value
  },
})
const submitLabel = computed(() => {
  if (isNew.value) return 'Extract audience'
  return campaign.value?.segment_rule ? 'Re-run extraction' : 'Extract audience'
})

async function open(): Promise<void> {
  await store.loadRules()
  if (props.id === undefined) store.startNew()
  else await store.loadCurrent(props.id)
}

async function submit(): Promise<void> {
  if (isNew.value) {
    const createdId = await store.createAndExtract(draftBrief.value)
    // Move to the campaign even when extraction failed, so "try again" works on the saved campaign.
    if (createdId !== null) await router.replace({ name: 'campaign', params: { id: createdId } })
    return
  }
  await store.extractAudience()
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
      <h2 class="text-lg font-semibold">{{ isNew ? 'New campaign' : `Campaign #${id}` }}</h2>
      <StatusBadge v-if="campaign" :status="campaign.status" />
    </div>

    <div aria-live="polite">
      <p v-if="store.composing && !campaign" class="text-slate-600">Loading…</p>
    </div>

    <template v-if="store.rules && (isNew || campaign)">
      <BriefStep
        v-model="briefText"
        :limits="store.rules.brief"
        :busy="store.composing"
        :readonly="!isNew"
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
          Not expressible: {{ criterion }}
        </li>
      </ul>
      <p
        v-if="store.composeError.status === 503 || store.composeError.status === 0"
        class="text-sm text-red-900"
      >
        The service is unreachable right now. Use the button above to try again.
      </p>
    </div>

    <p v-if="campaign && !canExtract" class="text-sm text-slate-600">
      This campaign is approved; its audience can no longer be changed.
    </p>

    <AudienceCard
      v-if="campaign?.segment_rule && store.rules"
      :campaign="campaign"
      :segment="store.rules.segment"
    />

    <ContentStream
      v-if="
        campaign && (campaign.status === 'audience_ready' || campaign.status === 'content_ready')
      "
      :key="campaign.id"
      :campaign="campaign"
      @completed="onContentGenerated"
    />

    <ContentEditor
      v-if="campaign?.messages?.length && store.rules"
      :key="`${campaign.id}-${contentVersion}`"
      :campaign="campaign"
      :rules="store.rules"
      @saved="store.current = $event"
    />
  </section>
</template>
