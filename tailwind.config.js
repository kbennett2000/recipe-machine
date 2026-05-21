import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
        './app/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                // Phase 10: self-hosted Inter + Fraunces. "Variable" suffix
                // matches the @font-face family names in resources/css/app.css.
                sans: ['Inter Variable', 'Inter', ...defaultTheme.fontFamily.sans],
                display: ['Fraunces Variable', 'Fraunces', 'Georgia', 'serif'],
                serif: ['Fraunces Variable', 'Fraunces', 'Georgia', 'serif'],
            },
        },
    },
    plugins: [],
};
