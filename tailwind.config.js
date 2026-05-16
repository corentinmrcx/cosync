/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.html.twig',
        './assets/**/*.js',
    ],
    theme: {
        extend: {
            colors: {
                primary: {
                    DEFAULT: '#ff3131',
                    dark: '#cc0000',
                    light: '#ffe5e5',
                },
                status: {
                    sent: '#3b82f6',
                    'sent-bg': '#eff6ff',
                    completed: '#f59e0b',
                    'completed-bg': '#fffbeb',
                    validated: '#22c55e',
                    'validated-bg': '#f0fdf4',
                },
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
};
