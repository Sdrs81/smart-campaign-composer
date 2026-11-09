<script setup lang="ts">
import { computed, nextTick, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import type { Campaign, ContentRules } from '../api/types'
import { useContentEditing } from '../composables/useContentEditing'
import { worstCaseLength } from '../text/worstCaseLength'
import CharCounter from './CharCounter.vue'
import EmailPreview from './EmailPreview.vue'
import SmsPreview from './SmsPreview.vue'

type FieldKey = 'smsBody' | 'emailSubject' | 'emailBody'

const props = defineProps<{ campaign: Campaign; rules: ContentRules }>()
const emit = defineEmits<{ saved: [campaign: Campaign]; 'can-approve': [allowed: boolean] }>()

const { t } = useI18n()
const ids = { smsBody: useId(), emailSubject: useId(), emailBody: useId() }
const SERVER_PATH: Record<FieldKey, string> = {
  smsBody: 'sms.body',
  emailSubject: 'email.subject',
  emailBody: 'email.body',
}

function textOf(channel: 'sms' | 'email', part: 'subject' | 'body'): string {
  return props.campaign.messages?.find((message) => message.channel === channel)?.[part] ?? ''
}

const form = reactive<Record<FieldKey, string>>({
  smsBody: textOf('sms', 'body'),
  emailSubject: textOf('email', 'subject'),
  emailBody: textOf('email', 'body'),
})
// The text is read once: the page re-creates the editor (new `key`) when new content is generated, and
// an editor's own saves must not rewrite what the person is typing.
const locked = computed(() => props.campaign.status !== 'content_ready')

const editing = useContentEditing(
  () => props.campaign.id,
  (saved) => emit('saved', saved),
)
const { saveState, fieldErrors, saveError, preview, previewError, previewCustomerId } = editing

function onEdit(): void {
  editing.edit({
    sms: { body: form.smsBody },
    email: { subject: form.emailSubject, body: form.emailBody },
  })
}

// Counts are the worst case after personalisation (K13), the same arithmetic as the server's validator.
const longestValues = computed(() => props.campaign.audience_counts?.longest_values ?? {})
function lengthOf(key: FieldKey): number {
  const channel = key === 'smsBody' ? 'sms' : 'email'
  return worstCaseLength(form[key], longestValues.value[channel] ?? {}, props.rules.variables)
}
const limits = computed<Record<FieldKey, number>>(() => ({
  smsBody: props.rules.limits.sms_body_max ?? 0,
  emailSubject: props.rules.limits.email_subject_max ?? 0,
  emailBody: props.rules.limits.email_body_max ?? 0,
}))
const counts = computed(() => ({
  smsBody: lengthOf('smsBody'),
  emailSubject: lengthOf('emailSubject'),
  emailBody: lengthOf('emailBody'),
}))
const withinLimits = computed(() =>
  (Object.keys(form) as FieldKey[]).every((key) => counts.value[key] <= limits.value[key]),
)
const filled = computed(() =>
  (Object.keys(form) as FieldKey[]).every((key) => form[key].trim() !== ''),
)

// Approval needs text that fits, is saved, and has no complaint from the server.
const canApprove = computed(
  () =>
    !locked.value &&
    withinLimits.value &&
    filled.value &&
    (saveState.value === 'idle' || saveState.value === 'saved') &&
    Object.keys(fieldErrors.value).length === 0,
)
watch(canApprove, (allowed) => emit('can-approve', allowed), { immediate: true })

function errorsOf(key: FieldKey): string[] {
  return fieldErrors.value[SERVER_PATH[key]] ?? []
}

// Bound by name from the template (`ref="smsBodyEl"`), so insertVariable can read the cursor.
const smsBodyEl = ref<HTMLTextAreaElement | null>(null)
const emailSubjectEl = ref<HTMLInputElement | null>(null)
const emailBodyEl = ref<HTMLTextAreaElement | null>(null)
const elementOf = (key: FieldKey) =>
  ({ smsBody: smsBodyEl, emailSubject: emailSubjectEl, emailBody: emailBodyEl })[key].value
const lastFocused = ref<FieldKey>('smsBody')

// Written in script because Vue would read "{{" inside a template expression as the start of an interpolation.
const placeholderOf = (name: string): string => `{{${name}}}`

async function insertVariable(name: string): Promise<void> {
  const key = lastFocused.value
  const element = elementOf(key)
  const placeholder = placeholderOf(name)
  const start = element?.selectionStart ?? form[key].length
  const end = element?.selectionEnd ?? start

  form[key] = form[key].slice(0, start) + placeholder + form[key].slice(end)
  onEdit()
  await nextTick()
  element?.focus()
  element?.setSelectionRange(start + placeholder.length, start + placeholder.length)
}

const messageOf = (channel: 'sms' | 'email') =>
  preview.value?.messages.find((message) => message.channel === channel)

// The message keys are the save states themselves; nothing is said while idle.
const saveLabel = computed(() => (saveState.value === 'idle' ? '' : t(`editor.${saveState.value}`)))

// Loaded once the text exists; refreshed by the composable after each save.
if (props.campaign.messages?.length) void editing.loadPreview()
</script>

<template>
  <section
    aria-labelledby="editor-heading"
    class="space-y-6 rounded border border-slate-200 bg-white p-4"
  >
    <div class="flex items-center justify-between gap-4">
      <h3 id="editor-heading" class="font-semibold">{{ t('editor.heading') }}</h3>
      <p v-if="!locked" aria-live="polite" class="text-sm text-slate-600">
        {{ saveLabel }}
      </p>
    </div>

    <p v-if="locked" class="rounded bg-slate-50 p-3 text-sm text-slate-700">
      {{ t('editor.locked') }}
    </p>
    <p
      v-if="saveError"
      role="alert"
      class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-900"
    >
      {{ saveError }}
    </p>

    <div
      v-if="!locked"
      class="flex flex-wrap items-center gap-2"
      role="group"
      :aria-label="t('editor.insertVariable')"
    >
      <span class="text-sm text-slate-600">{{ t('editor.insert') }}</span>
      <button
        v-for="variable in rules.variables"
        :key="variable.name"
        type="button"
        class="rounded border border-slate-300 px-2 py-1 font-mono text-xs hover:bg-slate-50 focus:outline-2 focus:outline-indigo-600"
        @click="insertVariable(variable.name)"
      >
        {{ placeholderOf(variable.name) }}
      </button>
    </div>

    <div class="space-y-2">
      <label :for="ids.smsBody" class="block text-sm font-medium">{{ t('editor.smsText') }}</label>
      <textarea
        :id="ids.smsBody"
        ref="smsBodyEl"
        v-model="form.smsBody"
        rows="3"
        :readonly="locked"
        :aria-invalid="counts.smsBody > limits.smsBody || errorsOf('smsBody').length > 0"
        class="w-full rounded border border-slate-300 p-3 focus:outline-2 focus:outline-indigo-600"
        @input="onEdit"
        @focus="lastFocused = 'smsBody'"
      />
      <CharCounter :count="counts.smsBody" :max="limits.smsBody" />
      <p
        v-for="message in errorsOf('smsBody')"
        :key="message"
        role="alert"
        class="text-sm text-red-700"
      >
        {{ message }}
      </p>
    </div>

    <div class="space-y-2">
      <label :for="ids.emailSubject" class="block text-sm font-medium">{{
        t('editor.emailSubject')
      }}</label>
      <input
        :id="ids.emailSubject"
        ref="emailSubjectEl"
        v-model="form.emailSubject"
        type="text"
        :readonly="locked"
        :aria-invalid="
          counts.emailSubject > limits.emailSubject || errorsOf('emailSubject').length > 0
        "
        class="w-full rounded border border-slate-300 p-3 focus:outline-2 focus:outline-indigo-600"
        @input="onEdit"
        @focus="lastFocused = 'emailSubject'"
      />
      <CharCounter :count="counts.emailSubject" :max="limits.emailSubject" />
      <p
        v-for="message in errorsOf('emailSubject')"
        :key="message"
        role="alert"
        class="text-sm text-red-700"
      >
        {{ message }}
      </p>
    </div>

    <div class="space-y-2">
      <label :for="ids.emailBody" class="block text-sm font-medium">{{
        t('editor.emailBody')
      }}</label>
      <textarea
        :id="ids.emailBody"
        ref="emailBodyEl"
        v-model="form.emailBody"
        rows="8"
        :readonly="locked"
        :aria-invalid="counts.emailBody > limits.emailBody || errorsOf('emailBody').length > 0"
        class="w-full rounded border border-slate-300 p-3 focus:outline-2 focus:outline-indigo-600"
        @input="onEdit"
        @focus="lastFocused = 'emailBody'"
      />
      <CharCounter :count="counts.emailBody" :max="limits.emailBody" />
      <p
        v-for="message in errorsOf('emailBody')"
        :key="message"
        role="alert"
        class="text-sm text-red-700"
      >
        {{ message }}
      </p>
    </div>

    <div class="space-y-4 border-t border-slate-200 pt-4">
      <div class="flex items-center gap-3">
        <label :for="`${ids.smsBody}-customer`" class="text-sm font-medium">{{
          t('editor.previewAs')
        }}</label>
        <select
          :id="`${ids.smsBody}-customer`"
          class="rounded border border-slate-300 p-2 text-sm focus:outline-2 focus:outline-indigo-600"
          :value="previewCustomerId ?? ''"
          @change="
            editing.selectCustomer(
              ($event.target as HTMLSelectElement).value === ''
                ? undefined
                : Number(($event.target as HTMLSelectElement).value),
            )
          "
        >
          <option value="">{{ t('editor.firstSample') }}</option>
          <option
            v-for="person in campaign.audience_counts?.sample ?? []"
            :key="person.id"
            :value="person.id"
          >
            {{ person.first_name ?? t('editor.noFirstName') }} {{ person.last_name }} ·
            {{ person.city }}
          </option>
        </select>
      </div>

      <p v-if="previewError" role="alert" class="text-sm text-red-700">{{ previewError }}</p>
      <div v-if="preview" class="grid gap-6 md:grid-cols-2">
        <div>
          <SmsPreview :body="messageOf('sms')?.body" :streaming="false" />
          <p class="mt-1 text-center text-xs text-slate-600">
            {{ t('editor.characters', { amount: messageOf('sms')?.lengths.body ?? 0 }) }}
          </p>
        </div>
        <div>
          <EmailPreview
            :subject="messageOf('email')?.subject ?? undefined"
            :body="messageOf('email')?.body"
            :streaming="false"
          />
          <p class="mt-1 text-xs text-slate-600">
            {{
              t('editor.emailLengths', {
                subject: messageOf('email')?.lengths.subject ?? 0,
                body: messageOf('email')?.lengths.body ?? 0,
              })
            }}
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
