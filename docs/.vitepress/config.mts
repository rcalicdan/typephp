import { defineConfig } from 'vitepress'

export default defineConfig({
  title: "TypePHP",
  description: "Runtime Type Enforcement for PHP.",
  themeConfig: {
    siteTitle: "TypePHP",
    nav: [
      { text: 'Home', link: '/' },
      { text: 'Documentation', link: '/getting-started/installation' },
      { text: 'Architecture', link: '/architecture/how-it-works' },
      { text: 'CLI', link: '/production/cache-commands' },
      { text: 'GitHub', link: 'https://github.com/typephp-php/typephp' }
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/getting-started/installation' },
          { text: 'Quick Start', link: '/getting-started/quick-start' },
          { text: 'Configuration', link: '/getting-started/configuration' },
        ]
      },
      {
        text: 'Architecture',
        items: [
          { text: 'How It Works', link: '/architecture/how-it-works' },
        ]
      },
      {
        text: 'Core Concepts',
        items: [
          { text: 'Function Contracts', link: '/core-concepts/function-contracts' },
          { text: 'Inline Variables', link: '/core-concepts/inline-variables' },
          { text: 'Property Validation', link: '/core-concepts/property-validation' },
          { text: 'Generics & Bounds', link: '/core-concepts/generics-and-bounds' },
          { text: 'Type Aliases', link: '/core-concepts/type-aliases' },
        ]
      },
      {
        text: 'Supported Types',
        items: [
          { text: 'Primitives & Scalars', link: '/supported-types/primitives-and-scalars' },
          { text: 'Arrays & Shapes', link: '/supported-types/arrays-and-shapes' },
          { text: 'Callables & Closures', link: '/supported-types/callables-and-closures' },
          { text: 'Iterators & Generators', link: '/supported-types/iterators-and-generators' },
          { text: 'Unions, Intersections & Conditionals', link: '/supported-types/unions-intersections-and-conditionals' },
        ]
      },
      {
        text: 'Advanced Features',
        items: [
          { text: 'Liskov & Inheritance', link: '/advanced/liskov-and-inheritance' },
          { text: 'Vendor Isolation', link: '/advanced/vendor-and-path-filtering' },
          { text: 'Ignore Annotations', link: '/advanced/ignore-annotations' },
          { text: 'Extensions', link: '/advanced/extensions' },
          { text: 'Exception Handling', link: '/advanced/exception-handling' },
        ]
      },
      {
        text: 'Production & Performance',
        items: [
          { text: 'Production Readiness', link: '/production/production-readiness' },
          { text: 'Cache CLI Commands', link: '/production/cache-commands' },
          { text: 'Performance Considerations', link: '/production/performance-considerations' },
        ]
      }
    ],
    socialLinks: [
      { icon: 'github', link: 'https://github.com/typephp-php/typephp' }
    ],
    search: {
      provider: 'local'
    }
  }
})