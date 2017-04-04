# laravel-apidoc-generator

Laravel API documentation generator, based on mpociot.

*(**Work in progress**... personal features on test).*

## Added Features

- Fix for models tranformers (reported here : [https://github.com/mpociot/laravel-apidoc-generator/issues/153](https://github.com/mpociot/laravel-apidoc-generator/issues/153))
- Extra logs
- Removed (int) explicit variable casting for UUID support in `setUserToBeImpersonated` method.
- Dingo Generator :
    - Custom models features support : Try to add extra model description for the routes
      (Pagination GET parameters settings `page` / `limit` values with models methods `getPerPageMin`, `getPerPageMax` and `getPerPage`).
    - `bindedUri` value in routes descriptions for example requests.
    - Faker values randomized for each routes (not only per resource).
    - Routes calling extra headers `'Content-Type' => 'application/json'` and `'Accept' => 'application/json'`.
    - Passport guard "api" user support (When using `actAsUserId`).
    - `uuid` rule support for fake values.
    - `strength` (password) rule support for fake values.

## PhpDoc

Use `@ApiDocsNoCall` tag on your Api controllers methods to ignore calling the route when generating the documentation
(Usefull for `update` and `destroy` methods)