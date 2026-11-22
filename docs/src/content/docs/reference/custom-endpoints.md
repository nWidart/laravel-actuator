---
title: Custom endpoints
description: The registration call, the operation attributes, and the rules for paths, parameters, responses, route names and discovery links of a custom endpoint.
---

A custom endpoint is a class registered under an ID. The built-in endpoints use the same mechanism.
For a walkthrough, see [write a custom endpoint](/laravel-actuator/guides/write-a-custom-endpoint/).

## Registration

```php
namespace Nwidart\Actuator;

final class Actuator
{
    /** @param class-string $class */
    public static function endpoint(string $id, string $class): void;
}
```

| Rule             | Description                                                                                         |
| ---------------- | --------------------------------------------------------------------------------------------------- |
| Where to call    | In `register()` or `boot()` of a service provider. `register()` is preferred.                       |
| Provider order   | Not relevant. The call works before and after the actuator provider is registered.                  |
| After boot       | A call after the application has booted throws `LogicException`.                                    |
| Exposure         | Registration does not expose the endpoint. See [exposure](#exposure-and-middleware).                |

### Endpoint IDs

The ID is the path segment after the base path, the key in `exposure`, the configuration key for
the endpoint middleware, and the prefix of the route names and discovery links.

| Rule      | Description                                                                                      |
| --------- | ------------------------------------------------------------------------------------------------ |
| Format    | Starts with a lowercase letter, then letters and digits only: `^[a-z][a-zA-Z0-9]*$`.             |
| Reserved  | `discovery`, `exposure` and `middleware`.                                                        |
| Unique    | An ID can be registered once. The comparison ignores case, so `scheduledTasks` collides with `scheduledtasks`. |
| Built-in  | `health`, `info`, `env`, `mappings` and `scheduledtasks` are already registered.                 |

A rejected ID throws `InvalidArgumentException` from `Actuator::endpoint()`.

## Operations

An operation is a public method of the endpoint class with one of these attributes, from the
`Nwidart\Actuator\Endpoints\Attributes` namespace:

| Attribute            | HTTP method   |
| -------------------- | ------------- |
| `#[ReadOperation]`   | `GET`, `HEAD` |
| `#[WriteOperation]`  | `POST`        |
| `#[DeleteOperation]` | `DELETE`      |

A method has at most one operation attribute. A class has at least one operation. Methods without
an attribute are ignored.

### Path

The path of an operation is the base path, the endpoint ID, then one segment for each selector, in
parameter order.

```php
#[ReadOperation]
public function part(#[Selector] string $name, #[Selector] string $part): array
```

```text
GET /actuator/widgets/{name}/{part}
```

Two operations of one class cannot share an HTTP method and a number of selectors.

### Parameters

| Parameter                              | Value                                                                    |
| -------------------------------------- | ------------------------------------------------------------------------ |
| `#[Selector] string $name`             | One path segment.                                                        |
| `#[Selector(remaining: true)] string $path` | The whole remaining path, slashes included. Only for the last selector. |
| A class or interface type              | Resolved by the container, for example `Illuminate\Http\Request`.        |

A selector must be typed `string` or have no type. A parameter that is not a selector must have a
class type. Query and body values are read from the request.

### Response

| The operation returns                                   | Response                                    |
| ------------------------------------------------------- | ------------------------------------------- |
| A `Symfony\Component\HttpFoundation\Response`           | That response, unchanged.                   |
| An `Illuminate\Contracts\Support\Responsable`           | The response it builds.                     |
| Any other value that is not `null`                      | HTTP 200 with the value encoded as JSON.    |
| `null` or nothing, from a read operation                | HTTP 404.                                   |
| `null` or nothing, from a write or delete operation     | HTTP 204.                                   |

The endpoint class and its operations are validated when the routes are registered and when the
discovery endpoint is called. An invalid class throws `InvalidArgumentException`.

## Exposure and middleware

A custom endpoint follows the same configuration as a built-in one.

| Setting                 | Effect                                                                                |
| ----------------------- | ------------------------------------------------------------------------------------- |
| `exposure.include`      | Lists the ID, or `'*'`, to expose the endpoint. Not exposed by default.               |
| `exposure.exclude`      | Lists the ID, or `'*'`, to keep the endpoint closed. Wins over `include`.             |
| `base_path`             | Prefix of every path of the endpoint.                                                 |
| `middleware`            | Applied to every operation of the endpoint.                                           |
| `{id}.middleware`       | Applied after `middleware`. Optional: without the key, there is no extra middleware.  |

An endpoint that is not exposed has no route, so it returns HTTP 404.

## Route names

Every operation has a named route. The name is `actuator.`, the endpoint ID, each selector name
joined with `-`, and a suffix for the HTTP method.

| Operation                                  | Route name                      |
| ------------------------------------------ | ------------------------------- |
| `GET /actuator/widgets`                    | `actuator.widgets`              |
| `GET /actuator/widgets/{name}`             | `actuator.widgets-name`         |
| `GET /actuator/widgets/{name}/{part}`      | `actuator.widgets-name-part`    |
| `POST /actuator/widgets/{name}`            | `actuator.widgets-name.write`   |
| `DELETE /actuator/widgets/{name}`          | `actuator.widgets-name.delete`  |

The routes work with `php artisan route:cache`. Rebuild the cache after you add an endpoint or
change its operations.

## Discovery links

The [discovery endpoint](/laravel-actuator/reference/endpoints/discovery/) lists one link for each
path of an exposed endpoint. Operations on the same path share the link.

| Path                                  | Link key             | `templated` |
| ------------------------------------- | -------------------- | ----------- |
| `/actuator/widgets`                   | `widgets`            | `false`     |
| `/actuator/widgets/{name}`            | `widgets-name`       | `true`      |
| `/actuator/widgets/{name}/{part}`     | `widgets-name-part`  | `true`      |
| `/actuator/health/{*path}`            | `health-path`        | `true`      |

A selector that matches the remaining path is written `{*name}` in the href.

## Differences from Spring Boot

The model follows the
[custom endpoints of Spring Boot Actuator](https://docs.spring.io/spring-boot/reference/actuator/endpoints.html#actuator.endpoints.implementing-custom).

| Spring Boot                                              | Laravel Actuator                                                  |
| -------------------------------------------------------- | ----------------------------------------------------------------- |
| `@Endpoint(id = "...")` on a bean                         | `Actuator::endpoint('id', Class::class)`                          |
| `@ReadOperation`, `@WriteOperation`, `@DeleteOperation`   | The same three attributes, on `GET`, `POST` and `DELETE`          |
| `@Selector`, `@Selector(match = ALL_REMAINING)`           | `#[Selector]`, `#[Selector(remaining: true)]`                     |
| Operation parameters bound from the query and the JSON body, HTTP 400 when one is missing | Not available. Inject `Request` and validate, HTTP 422 |
| `SecurityContext` or `Principal` parameter                | Inject `Request` and call `user()`                                |
| `@JmxEndpoint`, `@WebEndpoint`, JMX exposure              | Not available. Every endpoint is a web endpoint                   |
| `@EndpointWebExtension`                                   | Not available                                                     |
| `produces`, `consumes`, range requests                    | Not available. Return a response object                           |
| `management.endpoint.<id>.access`                         | Not available. Use `exposure` and middleware                      |
