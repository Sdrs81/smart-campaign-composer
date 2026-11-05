---
description: Vue 3 + TypeScript conventions for the frontend: components, state, streams, routing and accessibility.
paths:
  - "frontend/**/*.{ts,vue}"
---

# Frontend (Vue 3)

Always use `<script setup lang="ts">` with typed `defineProps<T>()` / `defineEmits<T>()`. Never use `any`; take `unknown` and narrow.
- The compiler then catches contract mistakes between components and the API.

Always keep shared state in Pinia stores and send HTTP calls through one api module or composable. Never call the API directly from a component.
- Components stay presentational, and the API layer is easy to mock.

Always load content limits and template variables from `/api/content/rules`, and count characters by grapheme with `Intl.Segmenter`. Never hardcode a limit or count with `.length`.
- `.length` miscounts emoji and combined characters, so the UI would disagree with the backend validator.

Always close what a composable opens (`EventSource.close()`, `AbortController.abort()`, timers, listeners via `onScopeDispose`). Never leave streams, timers or listeners running after unmount.
- Leaked streams keep running and pile up after navigation.

Always lazy-load routes with `() => import(...)`. Never import page components statically in the router.
- The first page then loads only the code it needs.

Always use `shallowRef` for large or frequently replaced objects such as streamed content and dashboard snapshots. Never make them deeply reactive.
- Deep reactivity re-walks the whole object on every update.

Always use real `<button>` and `<label>` elements, keep controls keyboard reachable with visible focus, and announce streamed text and status changes with `aria-live`. Never use a clickable `<div>` in place of a `<button>`.
- Keyboard and screen-reader users cannot see live updates otherwise.

Always style with Tailwind utilities. Never use inline styles.
- One styling system keeps the look consistent and themeable.
