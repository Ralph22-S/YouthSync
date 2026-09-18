/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      fontFamily: { sans: ['"Public Sans"', 'system-ui', 'sans-serif'] },
      colors: {
        // Brand
        navy: { 950: '#081625', 900: '#0B2038', 800: '#0F2C4C', 700: '#183E67', 600: '#22568C', 500: '#2F6BA8' },
        sun: { DEFAULT: '#E0A21A', 600: '#C88C0F', 100: '#FDF3DC' },
        // Semantic — one meaning per colour, used everywhere
        success: { DEFAULT: '#12664A', bg: '#E7F5EE', border: '#BFE3D0' },
        warning: { DEFAULT: '#8A6206', bg: '#FDF3DC', border: '#F2DCA8' },
        danger: { DEFAULT: '#A3231C', bg: '#FCEDEC', border: '#F0D3D1' },
        info: { DEFAULT: '#22568C', bg: '#E8EFF7', border: '#CBDCEF' },
        // Surfaces
        line: '#E4E8ED',
        'line-strong': '#CFD6DE',
        shell: '#F6F7F9',
        ink: { DEFAULT: '#0B2038', muted: '#54657A', subtle: '#7C8A9B' },
      },
      borderRadius: { card: '12px' },
      boxShadow: {
        card: '0 1px 2px rgba(11, 32, 56, 0.04)',
        raised: '0 4px 16px rgba(11, 32, 56, 0.08)',
        overlay: '0 16px 48px rgba(11, 32, 56, 0.18)',
      },
      fontSize: {
        'page-title': ['1.5rem', { lineHeight: '1.875rem', fontWeight: '600', letterSpacing: '-0.01em' }],
        'section-title': ['1.0625rem', { lineHeight: '1.5rem', fontWeight: '600' }],
        'card-title': ['0.875rem', { lineHeight: '1.25rem', fontWeight: '600' }],
      },
    },
  },
  plugins: [],
};
