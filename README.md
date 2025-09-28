# Laravel Vue Starter

This repository provides a starter kit for building modern web applications with [Laravel](https://laravel.com) and [Vue 3](https://vuejs.org) using the [Laravel Starter Kits (Vue)](https://laravel.com/starter-kits). It leverages Inertia.js for a single-page application (SPA) experience, Vite for rapid asset bundling, and Tailwind CSS for styling.

![Forum Page Example](https://i.imgur.com/gYNFkFl.png)

## Features

- **Laravel 12**: Robust backend framework.
- **Vue 3 with Inertia.js**: Build reactive, single-page applications.
- **Vite**: Modern build tool for fast development.
- **Tailwind CSS**: Utility-first CSS framework.
- **Authentication & Authorization**: Includes session based authentication scaffolding via Laravel Breeze (Vue variant) with role & permission management (using Spatie Permissions).
- **External API Authentication & Authorization**: Includes token based authentication scaffolding via [Laravel Sanctum](https://laravel.com/docs/12.x/sanctum).
- **Responsive Design**: Mobile-first design principles.
- **Admin Control Panel (ACP)**: Example layouts for managing users, blogs, forums, support tickets, external api access tokens and more.
- **Forum System**: Persistent categories, boards, threads, and posts with moderation tools (publish, lock, pin, delete) plus per-thread/post author editing and reporting workflows.
- **Forum Seeding Utilities**: A comprehensive `ForumDemoSeeder` seeds realistic boards, long threads, and paginated replies so you can explore the full forum experience locally.
- **Placeholder Components**: Starter components from shadcn-vue (like `PlaceholderPattern`) simulate content while you integrate dynamic data.
- **Role & Permission System**: Our project uses [Spatie's Laravel Permission](https://spatie.be/docs/laravel-permission/v6/introduction) [package](https://github.com/spatie/laravel-permission) to provide robust role and permission management. This integration, combined with Laravel Breeze for authentication and our Inertia.js SPA, enables us to enforce access control both on the backend and in our Vue frontend.

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

   > **Tip:** The forum schema includes pagination-ready relationships for boards, threads, and posts. Run the migrations before seeding demo data.

7. Build Assets & Start the Development Server:

   For development with hot reloading, run:
   ```bash
   npm run dev
   ```
   In another terminal, start your Laravel server (or use [Laravel Herd](https://herd.laravel.com)):
   ```bash
   php artisan serve
   ```

## Usage

- **Authentication:**
    The project includes authentication scaffolding using Laravel Breeze (Vue variant). Visit `/login` or `/register` to test user authentication.

- **Forum Demo Data:**
    Populate the forum with realistic categories, boards, and sample discussions by running:

    ```bash
    php artisan db:seed --class=ForumDemoSeeder
    ```

    The seeder resets the forum tables and creates:

    - Multiple boards with enough threads to exercise the board pagination controls.
    - At least one thread that contains 20+ replies, ensuring the thread pagination UI has real data.
    - Moderation-ready discussions with a mix of published, locked, and pinned states for testing.

    After seeding, visit `/forum` for the public experience or `/acp/forums` to review administrative listings. Actions like reporting, editing (for authors of unlocked, published content), and moderator toggles (publish, lock, pin, delete) are wired to live endpoints.

- **Admin Control Panel (ACP):**
    Access the ACP via routes like `/acp/dashboard`. The ACP layout includes side navigation for managing users, blogs, forums, and permissions.

- **Dynamic Content Integration:**
    Replace placeholder components (like `PlaceholderPattern`) with dynamic content from your models or API endpoints.

- **Styling & Customization:**
    Tailwind CSS is used for styling. Feel free to customize the design by modifying the Tailwind configuration or adding your own CSS.

### Using the Permission System in the Vue SPA

Since our frontend is built entirely in Vue with Inertia, we provide two TypeScript composables to facilitate role and permission checks.
#### useRoles.ts
```ts
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

interface Role {
  id: number;
  name: string;
  guard_name: string;
  created_at: string;
  updated_at: string;
}

interface AuthUser {
  id: number;
  name: string;
  email: string;
  roles?: Role[];
}

export function useRoles() {
  const page = usePage();
  const user = computed<AuthUser | null>(() => page.props.auth.user || null);

  /**
   * Check if the user has any of the given roles. 
   * Pass multiple roles separated by a pipe (e.g., "admin|moderator").
   */
  function hasRole(roles: string): boolean {
    const rolesToCheck = roles.split('|').map(r => r.trim());
    return !!(user.value && user.value.roles && user.value.roles.some(r => rolesToCheck.includes(r.name)));
  }

  return { hasRole };
}
```

#### usePermissions.ts
```ts
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function usePermissions() {
  const page = usePage();
  // We assume permissions are shared as an array of permission names.
  const permissions = computed<string[]>(() => page.props.auth.permissions || []);

  /**
   * Check if the user has any of the given permissions.
   * Pass multiple permissions separated by a pipe (e.g., "users.acp.manage|blogs.acp.manage").
   */
  function hasPermission(permissionsToCheck: string): boolean {
    const permissionList = permissionsToCheck.split('|').map(p => p.trim());
    return permissions.value.some(p => permissionList.includes(p));
  }

  return { hasPermission };
}
```

#### Example Usage in a Component
```vue
<script setup lang="ts">
import { computed } from 'vue';
import { useRoles } from '@/composables/useRoles';
import { usePermissions } from '@/composables/usePermissions';

const { hasRole } = useRoles();
const { hasPermission } = usePermissions();

const isAdmin = computed(() => hasRole('admin|super-admin'));
const canManageUsers = computed(() => hasPermission('users.acp.manage'));

console.log('User is admin:', isAdmin.value);
console.log('User can manage users:', canManageUsers.value);
</script>
```

## Contributing

Contributions are welcome! Please submit issues and pull requests for any improvements or bug fixes. When contributing, please follow the existing code style and add relevant tests.

## Useful Links

- [Laravel Documentation](https://laravel.com/docs/12.x)
- [Vue.js Documentation](https://vuejs.org/guide/quick-start.html)
- [Inertia.js Documentation](https://inertiajs.com/)
- [Laravel Starter Kits](https://laravel.com/docs/12.x/starter-kits#vue)
- [TailwindCSS Documentation](https://tailwindcss.com/docs/dark-mode)
- [shadcn-vue Component Library](https://www.shadcn-vue.com/)
- [Lucide Icons](https://lucide.dev/icons/)
- [Vue Sonner Toast Component](https://vue-sonner.vercel.app/)

## License

This project is open-sourced under the [MIT License](https://en.wikipedia.org/wiki/MIT_License).

## Final Notes

- **Flex & Height Adjustments:**  
  Ensure that your global CSS (or Tailwind config) sets `html, body, #app { height: 100%; }` or uses `min-h-screen` on the outermost container so that child elements with `h-full` and `flex-1` behave as expected.

- **Further Enhancements:**  
  As you continue developing, consider adding test suites and refining dynamic data integrations. Keep the README updated with any changes to setup or usage instructions.

This review and README should help guide developers new to the project and outline the next steps for further development. Happy coding!
