const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './frontend',
  timeout: 60000,
  retries: 1,
  use: {
    headless: true,
    viewport: { width: 1280, height: 720 },
  },
  reporter: [['list'], ['json', { outputFile: 'test-results.json' }]],
});
