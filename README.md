# Laravel Vue Starter

This repository provides a starter kit for building modern web applications with [Laravel](https://laravel.com) and [Vue 3](https://vuejs.org) using the [Laravel Starter Kits (Vue)](https://laravel.com/starter-kits). It leverages Inertia.js for a single-page application (SPA) experience, Vite for rapid asset bundling, and Tailwind CSS for styling.

## Features

- **Laravel 12**: Robust backend framework.
- **Vue 3 with Inertia.js**: Build reactive, single-page applications.
- **Vite**: Modern build tool for fast development.
- **Tailwind CSS**: Utility-first CSS framework.
- **Authentication & Authorization**: Includes authentication scaffolding via Laravel Breeze (Vue variant) with options to integrate role management (e.g., using Spatie Permissions).
- **Responsive Design**: Mobile-first design principles.
- **Admin Control Panel (ACP)**: Example layouts for managing users, blogs, forums, and more.
- **Placeholder Components**: Starter components (like `PlaceholderPattern`) simulate content while you integrate dynamic data.

## Setup & Installation

Follow these steps to set up the project locally:

1. **Clone the Repository:**

   ```bash
   git clone https://github.com/MetaGrenade/laravel-vue.git
   cd laravel-vue
   ```
   
2. Install PHP Dependencies:

    Make sure you have [Composer](https://getcomposer.org/) installed, then run:
    ```bash
    composer install
    ```
   
3. Install JavaScript Dependencies:

    Ensure that [Node.js](https://nodejs.org/) and npm are installed, then run:
    ```bash
    npm install
    ```

4. Copy the `.env.example` file to `.env` and configure your database and other environment variables:

    Ensure that [Node.js](https://nodejs.org/) and npm are installed, then run:
    ```bash
    cp .env.example .env
    ```
   Update the `.env` file with your database credentials (for example, for MariaDB):
   ```dotenv
    DB_CONNECTION=mysql
    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=your_database_name
    DB_USERNAME=your_username
    DB_PASSWORD=your_password
   ```
   
5. Generate Application Key:
   
   ```bash
   php artisan key:generate
   ```
   
6. Run Database Migrations:
   
   ```bash
   php artisan migrate
   ```
   
7. Build Assets & Start the Development Server:

    For development with hot reloading, run:
    ```bash
    npm run dev
    ```
    In another terminal, start your Laravel server (or use Laravel Herd):
    ```bash
    php artisan serve
    ```

## Usage

- **Authentication:**
    The project includes authentication scaffolding using Laravel Breeze (Vue variant). Visit `/login` or `/register` to test user authentication.

- **Admin Control Panel (ACP):**
    Access the ACP via routes like `/acp/dashboard`. The ACP layout includes side navigation for managing users, blogs, forums, and permissions.

- **Dynamic Content Integration:**
    Replace placeholder components (like `PlaceholderPattern`) with dynamic content from your models or API endpoints.

- **Styling & Customization:**
    Tailwind CSS is used for styling. Feel free to customize the design by modifying the Tailwind configuration or adding your own CSS.

## Contributing

Contributions are welcome! Please submit issues and pull requests for any improvements or bug fixes. When contributing, please follow the existing code style and add relevant tests.

## Useful Links

- [Laravel Documentation](https://laravel.com/docs/12.x)
- [Vue.js Documentation](https://vuejs.org/guide/quick-start.html)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Laravel Starter Kits](https://laravel.com/docs/12.x/starter-kits#vue)
- [shadcn-vue Component Library](https://www.shadcn-vue.com/)

## License

This project is open-sourced under the [MIT License](https://en.wikipedia.org/wiki/MIT_License).

## Final Notes

- **Flex & Height Adjustments:**  
  Ensure that your global CSS (or Tailwind config) sets `html, body, #app { height: 100%; }` or uses `min-h-screen` on the outermost container so that child elements with `h-full` and `flex-1` behave as expected.

- **Further Enhancements:**  
  As you continue developing, consider adding test suites and refining dynamic data integrations. Keep the README updated with any changes to setup or usage instructions.

This review and README should help guide developers new to the project and outline the next steps for further development. Happy coding!
