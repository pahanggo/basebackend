# Authenticating requests

This API is authenticated by sending an **`Authorization`** header with the value **`"Bearer {YOUR_AUTH_KEY}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

You can retrieve your token by logging in via the <code>/api/auth/login</code> endpoint.
