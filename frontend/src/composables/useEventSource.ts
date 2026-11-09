import { getCurrentScope, onScopeDispose, ref, type Ref } from 'vue'

export interface EventSourceOptions {
  /** Named server events to listen for, each given the raw `data` text. */
  handlers: Record<string, (data: string) => void>
  /** Events after which the source is closed, so the browser does not reconnect forever. */
  closeOn?: string[]
}

export interface EventSourceHandle {
  connected: Ref<boolean>
  /** True once the browser has given up (e.g. the server answered 404): it will not reconnect by itself. */
  lost: Ref<boolean>
  close: () => void
}

/** Opens a GET EventSource and closes it when the calling scope (component) is disposed. */
export function useEventSource(
  url: string,
  { handlers, closeOn = [] }: EventSourceOptions,
): EventSourceHandle {
  const connected = ref(false)
  const lost = ref(false)
  const source = new EventSource(url)

  const close = (): void => {
    source.close()
    connected.value = false
  }

  source.onopen = () => {
    connected.value = true
    lost.value = false
  }
  // A dropped connection makes the browser reconnect by itself (readyState stays CONNECTING); a refusal
  // such as 404 closes the source for good, and nothing will change unless the person reloads.
  source.onerror = () => {
    connected.value = source.readyState === EventSource.OPEN
    lost.value = source.readyState === EventSource.CLOSED
  }

  for (const [name, handler] of Object.entries(handlers)) {
    source.addEventListener(name, (event) => {
      handler((event as MessageEvent<string>).data)
      if (closeOn.includes(name)) close()
    })
  }
  for (const name of closeOn) {
    if (name in handlers) continue
    source.addEventListener(name, close)
  }

  if (getCurrentScope()) onScopeDispose(close)

  return { connected, lost, close }
}
