import { createRouter, createWebHistory } from 'vue-router'

// Every page is a dynamic import so the first load only fetches the code of the page it opens.
export const routes = [
  { path: '/', redirect: '/campaigns' },
  { path: '/campaigns', name: 'campaigns', component: () => import('../views/CampaignList.vue') },
  {
    path: '/campaigns/new',
    name: 'campaign-new',
    component: () => import('../views/CampaignNew.vue'),
  },
  {
    path: '/campaigns/:id(\\d+)',
    name: 'campaign',
    component: () => import('../views/CampaignWorkflow.vue'),
    props: (route: { params: Record<string, string | string[]> }) => ({
      id: Number(route.params.id),
    }),
  },
  {
    path: '/campaigns/:id(\\d+)/dashboard',
    name: 'campaign-dashboard',
    component: () => import('../views/CampaignDashboard.vue'),
    props: (route: { params: Record<string, string | string[]> }) => ({
      id: Number(route.params.id),
    }),
  },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

export default router
