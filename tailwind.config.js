import defaultTheme from 'tailwindcss/defaultTheme'
import forms from '@tailwindcss/forms'
import typography from '@tailwindcss/typography'

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                pctg: {
                    background: '#0b0d12',
                    surface: '#171a21',
                    elevated: '#212733',
                    primary: {
                        DEFAULT: '#e53935',
                        hover: '#ff5a52',
                    },
                    text: {
                        primary: '#ffffff',
                        secondary: '#9da7b8',
                    },
                    success: '#00C853',
                    warning: '#FFC107',
                },
            },
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', ...defaultTheme.fontFamily.sans],
                display: ['"Space Grotesk"', 'Inter', 'ui-sans-serif', 'system-ui', ...defaultTheme.fontFamily.sans],
            },
            borderRadius: {
                card: '24px',
                panel: '24px',
                button: '16px',
            },
            boxShadow: {
                card: '0 8px 32px rgba(0, 0, 0, 0.4)',
                panel: '0 16px 48px rgba(0, 0, 0, 0.5)',
                'glow-primary': '0 0 24px rgba(229, 57, 53, 0.35)',
                'glow-primary-strong': '0 0 48px rgba(229, 57, 53, 0.5)',
            },
            animation: {
                'power-pulse': 'powerPulse 2.4s ease-in-out infinite',
                'fade-in': 'fadeIn .4s ease-out both',
                'fade-in-up': 'fadeInUp .5s cubic-bezier(.16, 1, .3, 1) both',
                'scale-in': 'scaleIn .3s cubic-bezier(.16, 1, .3, 1) both',
                shimmer: 'shimmer 1.8s linear infinite',
            },
            keyframes: {
                powerPulse: {
                    '0%, 100%': { boxShadow: '0 0 0 0 rgba(229, 57, 53, 0.45)' },
                    '50%': { boxShadow: '0 0 28px 8px rgba(229, 57, 53, 0.28)' },
                },
                fadeIn: {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                fadeInUp: {
                    from: { opacity: '0', transform: 'translateY(16px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                scaleIn: {
                    from: { opacity: '0', transform: 'scale(.96)' },
                    to: { opacity: '1', transform: 'scale(1)' },
                },
                shimmer: {
                    from: { backgroundPosition: '200% 0' },
                    to: { backgroundPosition: '-200% 0' },
                },
            },
        },
    },
    plugins: [forms, typography],
}
