// Accessibility lint (PR-UX3, audit R4/C5). Deliberately narrow: only the
// jsx-a11y rules the UI/UX audit asked for, so it can gate CI without a
// project-wide style migration. Run with `npm run lint:a11y` (also part of
// `npm run typecheck`, which CI already executes).
import tsParser from '@typescript-eslint/parser';
import jsxA11y from 'eslint-plugin-jsx-a11y';
import iconButtonHasLabel from './eslint/icon-button-has-label.mjs';
import wibDates, { reactHooksStub } from './eslint/wib-dates.mjs';

export default [
    wibDates,
    {
        files: ['resources/js/**/*.tsx'],
        languageOptions: {
            parser: tsParser,
            parserOptions: { ecmaFeatures: { jsx: true } },
        },
        linterOptions: { reportUnusedDisableDirectives: 'off' },
        plugins: {
            'jsx-a11y': jsxA11y,
            skynet: { rules: { 'icon-button-has-label': iconButtonHasLabel } },
            // Source already carries `eslint-disable … react-hooks/exhaustive-deps`
            // comments; register the name (no-op) so they resolve without adding
            // the full react-hooks plugin to this a11y-only lint.
            'react-hooks': reactHooksStub,
        },
        settings: {
            'jsx-a11y': {
                // Map design-system components to the DOM element they render.
                components: { Button: 'button' },
            },
        },
        rules: {
            'skynet/icon-button-has-label': 'error',
            'jsx-a11y/control-has-associated-label': ['error', {
                labelAttributes: ['aria-label', 'aria-labelledby', 'title'],
                controlComponents: ['Button'],
                ignoreElements: ['audio', 'canvas', 'embed', 'input', 'textarea', 'tr', 'video', 'th', 'td', 'a', 'select', 'option'],
                ignoreRoles: ['grid', 'listbox', 'menu', 'menubar', 'radiogroup', 'row', 'tablist', 'toolbar', 'tree', 'treegrid', 'cell', 'gridcell', 'columnheader', 'rowheader', 'tabpanel'],
                depth: 4,
            }],
        },
    },
];
