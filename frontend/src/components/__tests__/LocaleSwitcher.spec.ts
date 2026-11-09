import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

import App from '../../App.vue'
import { routes } from '../../router'
import LocaleSwitcher from '../LocaleSwitcher.vue'

describe('LocaleSwitcher', () => {
  it('offers the other language by its own name', () => {
    const wrapper = mount(LocaleSwitcher)

    expect(wrapper.text()).toBe('Türkçe')
    expect(wrapper.attributes('aria-label')).toBe('Switch language to Türkçe')
    expect(wrapper.attributes('lang')).toBe('tr')
  })

  it('switches back and forth, and the button keeps offering the other language', async () => {
    const wrapper = mount(LocaleSwitcher)

    await wrapper.trigger('click')
    expect(wrapper.text()).toBe('English')
    expect(wrapper.attributes('aria-label')).toBe('Dili şuna çevir: English')
    expect(wrapper.attributes('lang')).toBe('en')

    await wrapper.trigger('click')
    expect(wrapper.text()).toBe('Türkçe')
  })
})

describe('language switch in the app', () => {
  it('turns the whole page Turkish and English again without a reload', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes })
    await router.push('/campaigns/new')
    await router.isReady()
    const wrapper = mount(App, { global: { plugins: [createPinia(), router] } })
    await flushPromises()
    expect(wrapper.text()).toContain('New campaign')

    await wrapper.find('header button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Yeni kampanya')
    expect(wrapper.text()).not.toContain('New campaign')
    expect(document.documentElement.lang).toBe('tr')

    await wrapper.find('header button').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('New campaign')
    expect(document.documentElement.lang).toBe('en')
  })
})
