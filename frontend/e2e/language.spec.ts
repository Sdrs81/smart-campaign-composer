import { expect, test } from '@playwright/test'

// The browser starts in English (see playwright.config.ts), so this is the first visit of an English reader.
test('the language button switches the interface and the choice survives a reload', async ({
  page,
}) => {
  await page.goto('/campaigns')
  await expect(page.getByRole('heading', { name: 'Campaigns' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')

  await page.getByRole('button', { name: 'Switch language to Türkçe' }).click()
  await expect(page.getByRole('heading', { name: 'Kampanyalar' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Yeni kampanya' })).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('lang', 'tr')
  await page.screenshot({ path: 'e2e/artifacts/07-turkish-list.png', fullPage: true })

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Kampanyalar' })).toBeVisible()

  await page.getByRole('button', { name: 'Dili şuna çevir: English' }).click()
  await expect(page.getByRole('heading', { name: 'Campaigns' })).toBeVisible()
})
