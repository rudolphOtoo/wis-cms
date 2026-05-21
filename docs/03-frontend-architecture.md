# Frontend Architecture

The frontend is built as a robust Single Page Application (SPA) using **React 19** and **Vite 8**. The source code resides in `resources/js/`.

## Directory Structure
- `resources/js/api/`: Axios configuration and API service wrappers.
- `resources/js/components/`: Reusable UI components (buttons, modals, layout shells).
- `resources/js/context/`: React Context providers (e.g., AuthContext) for global state management.
- `resources/js/hooks/`: Custom React hooks for abstracted logic.
- `resources/js/pages/`: Top-level view components mapped directly to routes.
- `resources/js/routes/`: Route definitions utilizing React Router 7.
- `resources/css/app.css`: Global styles and Tailwind directives.

## State Management
Currently, state is managed natively using React Hooks (`useState`, `useReducer`) and the **Context API**.
- **AuthContext**: Wraps the entire application. It stores the authenticated user's details, roles, and current token, making it accessible to any deeply nested component without prop-drilling.

## API Integration & Axios Interceptors
The application abstracts API calls into dedicated service files (e.g., `api/members.js`). Axios is globally configured with interceptors:
- **Request Interceptor**: Injects the Bearer token into headers automatically on every request.
- **Response Interceptor**: Catches `401 Unauthorized` responses globally to trigger an automatic logout and redirect to the login screen, ensuring expired tokens are handled gracefully without application crashes.

## Routing and Protection
React Router 7 manages navigation. Routes are divided into two main categories:
1. **Public Routes**: The `/login` page.
2. **Protected Routes**: Wrapped in an `AuthRoute` component. This component checks the `AuthContext`; if the user is not authenticated, they are immediately redirected to `/login`.

## Styling Strategy
The UI heavily relies on **Tailwind CSS v4**.
- Styling is applied directly via utility classes in JSX.
- A custom color palette (navy and gold themes) is configured in the project to match the church's branding requirements.
- Complex layouts like the Dashboard grid and Sidebar navigation utilize modern CSS Grid and Flexbox techniques provided by Tailwind utilities.
