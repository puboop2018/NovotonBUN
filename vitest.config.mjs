import { defineConfig } from 'vitest/config';

// Behavioral tests for the hand-maintained browser JS (plain script-mode
// IIFEs — no build step; suites import the files for their side effects and
// drive them through window/DOM). The React engine has its own webpack
// pipeline and is NOT covered here.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.mjs'],
        // The modules under test attach state to window/document at import;
        // isolate each suite file in its own environment.
        isolate: true,
    },
});
