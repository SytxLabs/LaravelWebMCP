import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['js-tests/**/*.test.js'],
        setupFiles: ['js-tests/setup.js'],
    },
});
