const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080/ERP';

test.describe('ERP/CRM E2E', () => {

  test('Frontend: login, dashboard, and stock page flow', async ({ page }) => {
    // 1. Redirect to login when unauthenticated
    await page.goto(BASE_URL + '/index.php');
    await expect(page).toHaveURL(/login\.php/);
    await expect(page.locator('h1')).toContainText('ERP/CRM');

    // 2. Login with default credentials
    await page.fill('#username', 'admin');
    await page.fill('#password', 'admin');
    await page.click('button[type="submit"]');
    await page.waitForURL(/index\.php/);
    await expect(page.locator('.navbar-brand')).toBeVisible();

    // 3. Dashboard loads with stats cards
    await expect(page.locator('.card')).toHaveCount.atLeast(3);

    // 4. Navigate to Stock via nav
    await page.click('a[href*="modules/stock.php"]');
    await page.waitForURL(/modules\/stock\.php/);
    await expect(page.locator('h4')).toContainText('Склад');

    // 5. Create a new product
    await page.click('button[data-bs-target="#createProductModal"]');
    await page.waitForSelector('#createProductModal.show');
    await page.fill('#createProductModal input[name="name"]', 'Test E2E Product');
    await page.fill('#createProductModal input[name="model"]', 'E2E-TEST-001');
    await page.click('#createProductModal button[type="submit"]');
    await page.waitForURL(/modules\/stock\.php/);
    await expect(page.locator('.alert-success')).toBeVisible();

    // 6. Verify product appears in table
    await expect(page.locator('table')).toContainText('E2E-TEST-001');
  });

});
