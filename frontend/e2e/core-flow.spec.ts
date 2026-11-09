import { expect, test, type Page } from '@playwright/test'

const BRIEF = 'Sepetini terk eden müşterilere yüzde yirmi indirim gönder'
const EDITED_SMS =
  'Merhaba {{first_name}}, sepetindeki ürünler seni bekliyor! Yüzde 20 indirim bugün geçerli.'
const ARTIFACTS = 'e2e/artifacts'

// The mock provider takes a few seconds per few hundred messages, and fault injection adds retries.
const SENDING_TIMEOUT_MS = 120_000

async function capture(page: Page, name: string): Promise<void> {
  await page.screenshot({ path: `${ARTIFACTS}/${name}.png`, fullPage: true })
}

test('brief → audience → streamed content → edit → approve → dashboard completed', async ({
  page,
}) => {
  await test.step('write a brief and extract the audience', async () => {
    await page.goto('/campaigns/new')
    await page.getByLabel('Campaign brief').fill(BRIEF)
    await expect(page.getByRole('button', { name: 'Extract audience' })).toBeEnabled()
    await page.getByRole('button', { name: 'Extract audience' }).click()

    await expect(page).toHaveURL(/\/campaigns\/\d+$/)
    await expect(
      page.getByRole('list', { name: 'Rule' }).getByRole('listitem').first(),
    ).toBeVisible()
    // SMS, Email and Total are real numbers from the seeded customers.
    const totals = page.getByRole('region', { name: 'Audience' }).locator('dd')
    await expect(totals).toHaveCount(3)
    await expect(totals.nth(2)).not.toHaveText('0')
    await capture(page, '01-audience')
  })

  await test.step('generate content and watch it stream in', async () => {
    await page.getByRole('button', { name: 'Generate content' }).click()
    await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible()
    await expect(page.getByText('Writing content…')).toBeVisible()
    await capture(page, '02-streaming')

    // The checked text replaces the stream and the editor takes over.
    await expect(page.getByRole('heading', { name: 'Edit & preview' })).toBeVisible()
    await expect(page.getByLabel('SMS text')).not.toHaveValue('')
    await expect(page.getByLabel('Email subject')).not.toHaveValue('')
    await capture(page, '03-content-ready')
  })

  await test.step('edit the SMS and see it saved', async () => {
    await page.getByLabel('SMS text').fill(EDITED_SMS)
    await expect(page.getByText('Saved', { exact: true })).toBeVisible()
    await expect(page.getByLabel('SMS text')).toHaveValue(EDITED_SMS)
    await expect(page.getByRole('button', { name: 'Approve campaign' })).toBeEnabled()
    await capture(page, '04-edited')
  })

  await test.step('approve and land on the dashboard', async () => {
    await page.getByRole('button', { name: 'Approve campaign' }).click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await capture(page, '05-approve-dialog')

    await dialog.getByRole('button', { name: 'Approve and send' }).click()
    await expect(page).toHaveURL(/\/campaigns\/\d+\/dashboard$/)
  })

  await test.step('wait until sending is completed', async () => {
    await expect(page.getByText('Finished', { exact: true })).toBeVisible({
      timeout: SENDING_TIMEOUT_MS,
    })
    await expect(page.getByText('Completed', { exact: true })).toBeVisible()

    // Nothing is left waiting in either channel, and something was really sent.
    for (const channel of ['SMS', 'Email']) {
      const card = page
        .getByRole('heading', { name: channel, exact: true })
        .locator('xpath=ancestor::section[1]')
      const [sent, , , waiting] = await card.locator('dd').allTextContents()
      expect(Number(sent), `${channel} sent`).toBeGreaterThan(0)
      expect(Number(waiting), `${channel} waiting`).toBe(0)
    }
    await capture(page, '06-dashboard-completed')
  })
})
