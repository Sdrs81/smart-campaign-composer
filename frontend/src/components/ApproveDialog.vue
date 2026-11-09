<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'

import type { Campaign } from '../api/types'
import { useCampaignStore } from '../stores/campaign'

const props = defineProps<{ campaign: Campaign; canApprove: boolean }>()
const emit = defineEmits<{ approved: [campaign: Campaign] }>()

const store = useCampaignStore()
const titleId = useId()
const open = ref(false)
const openButton = ref<HTMLButtonElement | null>(null)
const dialog = ref<HTMLElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)

const counts = computed(() => [
  { label: 'SMS', value: props.campaign.audience_counts?.channel_counts.sms ?? 0 },
  { label: 'Email', value: props.campaign.audience_counts?.channel_counts.email ?? 0 },
  { label: 'Total', value: props.campaign.audience_counts?.unique_total ?? 0 },
])
const problems = computed(() => {
  const error = store.approvalError
  if (!error) return []
  return [
    ...Object.entries(error.fieldErrors).flatMap(([path, messages]) =>
      messages.map((message) => `${path}: ${message}`),
    ),
    ...error.problems,
  ]
})

async function show(): Promise<void> {
  store.clearApprovalError()
  open.value = true
  await nextTick()
  // Focus lands on the safe choice: approving starts real sending and cannot be undone.
  cancelButton.value?.focus()
}

function close(): void {
  // The request is already on its way; closing now would hide its answer.
  if (store.approving) return
  open.value = false
  void nextTick(() => openButton.value?.focus())
}

async function confirm(): Promise<void> {
  const approved = await store.approveCurrent()
  if (!approved) return
  open.value = false
  emit('approved', approved)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    close()
    return
  }
  if (event.key !== 'Tab' || !dialog.value) return

  // Keeps Tab inside the dialog while the page behind it is not usable.
  const focusable = [...dialog.value.querySelectorAll<HTMLElement>('button:not([disabled])')]
  const first = focusable[0]
  const last = focusable.at(-1)
  if (!first || !last) return
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onBeforeUnmount(() => store.clearApprovalError())
</script>

<template>
  <div>
    <button
      ref="openButton"
      type="button"
      class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-emerald-600"
      :disabled="!canApprove"
      @click="show"
    >
      Approve campaign
    </button>
    <p v-if="!canApprove" class="mt-1 text-sm text-slate-600">
      Fix the content and wait for it to be saved before approving.
    </p>

    <div
      v-if="open"
      class="fixed inset-0 z-10 flex items-center justify-center bg-black/40 p-4"
      @keydown="onKeydown"
    >
      <div
        ref="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        class="w-full max-w-md space-y-4 rounded bg-white p-6 shadow-lg"
      >
        <h2 :id="titleId" class="text-lg font-semibold">Approve and send?</h2>
        <p class="text-sm text-slate-700">
          Sending starts right away to everyone below. This cannot be undone.
        </p>

        <dl class="grid grid-cols-3 gap-3">
          <div
            v-for="count in counts"
            :key="count.label"
            class="rounded bg-slate-50 p-3 text-center"
          >
            <dt class="text-sm text-slate-600">{{ count.label }}</dt>
            <dd class="text-xl font-semibold">{{ count.value }}</dd>
          </div>
        </dl>

        <div
          v-if="store.approvalError"
          role="alert"
          class="space-y-1 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-900"
        >
          <p class="font-medium">{{ store.approvalError.message }}</p>
          <ul v-if="problems.length > 0" class="list-disc pl-5">
            <li v-for="problem in problems" :key="problem">{{ problem }}</li>
          </ul>
        </div>

        <div class="flex justify-end gap-3">
          <button
            ref="cancelButton"
            type="button"
            class="rounded border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-indigo-600"
            :disabled="store.approving"
            @click="close"
          >
            Cancel
          </button>
          <button
            type="button"
            class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50 focus:outline-2 focus:outline-offset-2 focus:outline-emerald-600"
            :disabled="store.approving"
            @click="confirm"
          >
            {{ store.approving ? 'Approving…' : 'Approve and send' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
