// WIB-everywhere guard: every date/time shown to or typed by a user goes
// through resources/js/lib/dateTime.ts (WIB, Asia/Jakarta). Warn level while
// the remaining out-of-scope pages are migrated (see the WIB PR series).
import tsParser from '@typescript-eslint/parser';

const RESTRICTED = [
    {
        selector: "CallExpression[callee.property.name=/^toLocale(Date|Time)?String$/]",
        message: 'Use formatWib* from @/lib/dateTime (WIB). toLocale*String uses the browser time zone.',
    },
    {
        selector: "CallExpression[callee.property.name='slice'][callee.object.callee.property.name='toISOString']",
        message: 'toISOString().slice is UTC. Use todayWib/currentMonthWib/toWibDateTimeLocal from @/lib/dateTime.',
    },
    {
        selector: "NewExpression[callee.object.name='Intl'][callee.property.name='DateTimeFormat']:not(:has(Property[key.name='timeZone']))",
        message: 'Intl.DateTimeFormat needs an explicit timeZone (use @/lib/dateTime).',
    },
    {
        selector: "CallExpression[callee.object.name='Intl'][callee.property.name='DateTimeFormat']:not(:has(Property[key.name='timeZone']))",
        message: 'Intl.DateTimeFormat needs an explicit timeZone (use @/lib/dateTime).',
    },
];

// Same no-op plugin object is reused by eslint.config.mjs (a plugin name may
// only be bound to one object across the flat config).
export const reactHooksStub = { rules: { 'exhaustive-deps': { meta: { schema: [] }, create: () => ({}) } } };

export default {
    files: ['resources/js/**/*.ts', 'resources/js/**/*.tsx'],
    ignores: [
        'resources/js/lib/dateTime.ts',
        'resources/js/lib/format.ts',
        'resources/js/Components/monitoring/network/format.ts',
        'resources/js/Components/monitoring/router/format.ts',
    ],
    languageOptions: {
        parser: tsParser,
        parserOptions: { ecmaFeatures: { jsx: true } },
    },
    linterOptions: { reportUnusedDisableDirectives: 'off' },
    plugins: {
        // Source carries `eslint-disable react-hooks/exhaustive-deps` comments; register the name.
        'react-hooks': reactHooksStub,
    },
    rules: {
        'no-restricted-syntax': ['warn', ...RESTRICTED],
    },
};
