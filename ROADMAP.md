# Roadmap

## 2.0

- `Status` becomes a value object that accepts any status code, like Spring Boot's custom `Status`.
  Custom codes can then be listed in `health.status.order` and `health.status.http_mapping`. This
  breaks code that `match`es on the `Status` enum or type-checks its cases. Tracked in
  [#36](https://github.com/nWidart/laravel-actuator/issues/36).
