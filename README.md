# portalium/yii2-developer

API reference for a Portalium (Yii2) project. It builds an **OpenAPI 3.0.3** document from the API application's
code on request and shows it with [Swagger UI](https://github.com/swagger-api/swagger-ui) at `/developer/default/index`.

- The document comes from the code itself: every server shows its own API, and nothing is generated at build time.
- No runtime dependency: Swagger UI is bundled (`src/assets/swagger/vendor`), and the attributes are plain PHP classes.
- Swagger UI runs on a page of its own (`frame`) shown in an iframe, so the application's Bootstrap styles cannot
  change its layout. The frame follows the application's `data-bs-theme` (light/dark, `html.dark-mode`).
- Operations are **internal unless marked public**. Customers see only public operations; internal ones, and the
  schemas only they use, are removed on the server (also from the downloaded JSON).

## Installation

Projects whose `vendor/` is copied rather than managed by composer:

```bash
bash bin/install.sh <project-root>
```

Then:

1. `web/config/main.php` → `'modules'`:
   ```php
   'developer' => ['class' => 'portalium\developer\Module', 'title' => 'My API'],
   ```
   Keep the module id from starting with `api`: a typical root `.htaccess` sends every `/api…` URL to the API
   application (`RewriteCond %{REQUEST_URI} ^/(api)`).
2. Migrations (permissions, then the top menu item):
   ```bash
   php portalium migrate --migrationPath=@vendor/portalium/yii2-developer/src/migrations --interactive=0
   php portalium migrate --migrationPath=@vendor/portalium/yii2-developer/src/migrations/menu --interactive=0
   ```

| Permission | Given to | Sees |
|---|---|---|
| `developerWebDefaultIndex` | `admin`, `user` | public operations |
| `developerWebDefaultInternal` | `admin` | public and internal operations |

Module options: `title`, `version`, `intro` (Markdown), `serverUrl` (default: current host + `/api`),
`configFiles` (API config files whose `modules` are documented), `modules` (`id => class`, instead of the config
files), `swagger` (extra Swagger UI options).

## What is read from the code

For every module of the API application, the controllers in `<module>/controllers/api`:

- URLs from the module's `apiRules` (`yii\rest\UrlRule`: `pluralize`, `prefix`, `patterns`, `extraPatterns`,
  `only`/`except`), intersected with the controller's `verbs()`. Actions no rule covers are documented on the
  default route `module/controller/action`.
- `yii\rest\ActiveController` actions: list (pagination headers, `page`, `per-page`, `sort`, `filter` when
  `dataFilter` is set, `fields`, `expand`), view, create (201), update, delete (204); the model schema comes from
  the table columns and `fields()`, the request body from the safe attributes and validation rules.
- Action method arguments become query parameters; the first paragraph of the method's PHPDoc is the description.
- Authentication from the `authenticator` behavior (`only`, `except`, `optional`; bearer and `access-token`).

## Describing operations

```php
use portalium\developer\attributes as Doc;

#[Doc\Tag('Notifications', 'In-app notifications of the signed-in user.')]
#[Doc\Operation(action: 'index', audience: Doc\Operation::PUBLIC, summary: 'List my notifications')]
#[Doc\Body(action: 'update', fields: ['status' => ['type' => 'integer', 'enum' => [0, 1]]], required: ['status'])]
class DefaultController extends ActiveController
{
    #[Doc\Operation(audience: Doc\Operation::PUBLIC, summary: 'Send to many users')]
    #[Doc\Body(NotificationForm::class)]                 // schema from the form's rules
    #[Doc\Response(200, 'Created.', fields: ['count' => 'integer - Users notified'])]
    #[Doc\Response(422, ref: 'ValidationErrors')]
    #[Doc\Permission('notificationApiDefaultSendAll', note: 'notificationType all')]
    public function actionSend() {}
}
```

On the class, an attribute applies to the actions in `action:` (or to every action when it is omitted); on a method,
to that action. Method values win.

| Attribute | Use |
|---|---|
| `Operation` | `summary`, `description`, `audience` (`PUBLIC`/`INTERNAL`), `auth` (override), `method` (for default routes without `verbs()`), `deprecated` |
| `Tag` | sidebar group of the controller |
| `Body` | `model` (form/model class), `fields` + `required`, `exclude`, `scenario`, `partial` |
| `Response` | `code`, `description`, `model`, `fields`, `list`, `ref` (`Error`, `ValidationErrors`) |
| `Param` | extra query/path/header parameter |
| `Permission` | permission(s) the action checks; shown in the description and `x-permissions` |
| `Hidden` | leave the action (or controller) out for everyone |

Shorthand types: `string`, `integer`, `number`, `boolean`, `object`, `array`; `?` nullable (`string?`), `[]` list
(`integer[]`), `string:email` format, `enum:a,b,c`, ` - text` description. A nested array of fields is an object; an
array with `type`/`$ref`/`enum`/`items` is used as a schema as is.

## Cache

The document is cached per audience in the `cache` component and rebuilt when any file it was built from (module,
controller and model files, config files) changes.

## Updating Swagger UI

Download `swagger-ui-bundle.js`, `swagger-ui.css`, `swagger-ui-bundle.js.LICENSE.txt`, `LICENSE` and `NOTICE` of a
pinned `swagger-ui-dist` version that is at least two weeks old into `src/assets/swagger/vendor` (LICENSE and NOTICE
as `swagger-ui-LICENSE` and `swagger-ui-NOTICE`) and update `src/assets/swagger/vendor/VERSION`.
`src/assets/swagger/js/developer.js` turns off the validator badge (`validatorUrl: null`, otherwise the document URL
is sent to validator.swagger.io) and URL overrides from the address bar (`queryConfigEnabled: false`).

## Tests

```bash
docker exec -w /app/visu yii2-php vendor/bin/phpunit vendor/portalium/yii2-developer/tests
```

`tests/data/openapi-3.0-schema.json` is the official OpenAPI 3.0 schema (2021-09-28); the tests validate the
generated documents against it.
