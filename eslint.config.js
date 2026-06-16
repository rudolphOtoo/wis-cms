import js from '@eslint/js'
import react from 'eslint-plugin-react'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import globals from 'globals'

export default [
  // Ignore build output, deps, and PHP/vendor land
  {
    ignores: [
      'public/build/**',
      'node_modules/**',
      'vendor/**',
      'bootstrap/ssr/**',
      '*.config.js',
    ],
  },

  js.configs.recommended,

  {
    files: ['resources/js/**/*.{js,jsx}'],
    plugins: {
      react,
      'react-hooks': reactHooks,
      'react-refresh': reactRefresh,
    },
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.es2021,
      },
      parserOptions: {
        ecmaFeatures: { jsx: true },
      },
    },
    settings: {
      react: { version: 'detect' },
    },
    rules: {
      ...react.configs.recommended.rules,
      ...reactHooks.configs.recommended.rules,

      // React 19 / new JSX transform — no need to import React in scope
      'react/react-in-jsx-scope': 'off',
      'react/jsx-uses-react': 'off',
      // We don't use prop-types in this project
      'react/prop-types': 'off',

      // Real-bug class → errors
      'no-undef': 'error',
      'no-unreachable': 'error',
      'react-hooks/rules-of-hooks': 'error',

      // Advisory / stylistic → warnings (don't block, don't drown us)
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
      'react-hooks/exhaustive-deps': 'warn',
      'react-refresh/only-export-components': 'off',

      // These are opinionated/cosmetic, not bug-class — keep advisory:
      // setState-in-effect is a common working pattern here; unescaped
      // apostrophes in JSX text are purely cosmetic.
      'react-hooks/set-state-in-effect': 'off',
      'react/no-unescaped-entities': 'off',
      'react/display-name': 'off',

      // Advisory for this codebase: a few small presentational helpers
      // are defined inside page components. Harmless for stateless
      // helpers; tracked as a follow-up to hoist to module scope.
      'react-hooks/static-components': 'warn',
      // Empty catch blocks are intentional in best-effort operations
      // (e.g. clipboard fallback); warn rather than error.
      'no-empty': 'warn',
    },
  },
]
