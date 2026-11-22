---
title: Define health groups
description: Group a subset of health indicators under their own path, with their own visibility.
---

A health group checks a subset of the indicators and answers at `/actuator/health/{group}`. The
package defines two groups for [Kubernetes probes](/laravel-actuator/guides/kubernetes-probes/):

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState']],
],
```

## Add an indicator to a group

List the indicator names in `include`. Each name must be a key of `health.indicators`:

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState', 'db', 'cache']],
],
```

A name that is not an indicator makes the health endpoint throw an exception, so a typo shows up at
the first request.

:::caution
Think before you add shared dependencies such as `db` to `readiness`. When the shared database
fails, every instance becomes unready at the same moment. See
[liveness and readiness](/laravel-actuator/concepts/liveness-and-readiness/).
:::

## Add a group

Add a key to `groups`. For example, a group that a dashboard polls for the external services:

```php title="config/actuator.php"
'groups' => [
    'liveness' => ['include' => ['ping']],
    'readiness' => ['include' => ['readinessState']],
    'external' => ['include' => ['paymentGateway', 'redis']],
],
```

```bash
curl http://localhost/actuator/health/external
```

Use a group name that is not also an indicator name. When both exist, the path selects the group.

## Change visibility for one group

A group can override `show_details` and `show_components`. The group setting wins over the
endpoint setting:

```php title="config/actuator.php"
'show_details' => 'when_authorized',

'groups' => [
    'liveness' => ['include' => ['ping'], 'show_details' => 'never'],
    'external' => ['include' => ['paymentGateway', 'redis'], 'show_details' => 'always'],
],
```

## Read one component of a group

When components are visible, each component of a group has its own path:

```bash
curl http://localhost/actuator/health/readiness/db
```

## Remove the default groups

Set `groups` to an empty array. The root response then omits the `groups` key:

```php title="config/actuator.php"
'groups' => [],
```

## See also

- [Health groups reference](/laravel-actuator/reference/endpoints/health/#groups)
- [Configure Kubernetes probes](/laravel-actuator/guides/kubernetes-probes/)
