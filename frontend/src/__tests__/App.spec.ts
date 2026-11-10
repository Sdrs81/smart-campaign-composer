import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import App from '../App.vue'

describe('App', () => {
  it('renders the application shell', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/campaigns', name: 'campaigns', component: { template: '<p />' } }],
    })
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, { global: { plugins: [createPinia(), router] } })

    expect(wrapper.find('h1').text()).toBe('Smart Campaign Composer')
    expect(wrapper.find('h1 a').attributes('href')).toBe('/campaigns')
    expect(wrapper.find('main').exists()).toBe(true)
  })
})
