---
name: add-route
description: "Add or change an ExeLearning route, controller action, or endpoint URL."
---

# Add a route

1. Read the nearest existing route and its controller/factory in `config/module.config.php`.
   Keep admin routes under `admin.child_routes`; custom `/api/exelearning` routes are plain MVC routes,
   not automatically authenticated Omeka REST adapters.
2. Register the controller, factory and alias consistently. Use the actual action-name convention
   from that controller; validate IDs and all request inputs.
3. Keep CSRF and ACL checks for mutations. Test denied direct requests as well as successful UI use;
   neither navigation visibility nor a route prefix authorizes a caller.
4. Add a view only for HTML responses. Reuse current JSON/API-problem response patterns otherwise.
5. Generate route URLs through existing helpers and `Module::extractBasePath()` where needed.
   Do not use `$request->getBasePath()` for scoped installations.
6. Add a controller test under `test/ExeLearningTest/Controller/`, and run `make lint` and
   `make test-coverage`. Check subpath URLs when changing client-facing routes.
