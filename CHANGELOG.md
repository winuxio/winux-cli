# Changelog

## [0.3.4](https://github.com/winuxio/winux-cli/compare/v0.3.3...v0.3.4) (2026-10-29)


### Features

* create, import and delete projects without a password ([#25](https://github.com/winuxio/winux-cli/issues/25)) ([86c03ea](https://github.com/winuxio/winux-cli/commit/86c03eaee851dbd38daa08a934bc1a526145cc3d))
* offer Boost and 14 more packages in project:create ([#23](https://github.com/winuxio/winux-cli/issues/23)) ([dfc67bc](https://github.com/winuxio/winux-cli/commit/dfc67bc362bec9b3d4ddd242864d977183f62839))

## [0.3.3](https://github.com/winuxio/winux-cli/compare/v0.3.2...v0.3.3) (2026-10-29)


### Features

* create a project in a directory that already has files ([#21](https://github.com/winuxio/winux-cli/issues/21)) ([6c8583c](https://github.com/winuxio/winux-cli/commit/6c8583c80bdb63f40864ff29b93dc85f3ec87627))

## [0.3.2](https://github.com/winuxio/winux-cli/compare/v0.3.1...v0.3.2) (2026-10-29)


### Features

* let project:import take . or a path, defaulting to the current directory ([#19](https://github.com/winuxio/winux-cli/issues/19)) ([989136b](https://github.com/winuxio/winux-cli/commit/989136bc3cd7c955cb2b321cfdfa88afbbe8b21e))

## [0.3.1](https://github.com/winuxio/winux-cli/compare/v0.3.0...v0.3.1) (2026-10-29)


### Features

* color the doctor's check marks green and red ([#17](https://github.com/winuxio/winux-cli/issues/17)) ([4ef3dee](https://github.com/winuxio/winux-cli/commit/4ef3dee262797450a60471c43a226078235c176f))


### Bug Fixes

* show the remove-old-winux prompt in install.sh ([#16](https://github.com/winuxio/winux-cli/issues/16)) ([4451d7c](https://github.com/winuxio/winux-cli/commit/4451d7cab7db621e57e1c179199b9d61de5f2ea1))

## [0.3.0](https://github.com/winuxio/winux-cli/compare/v0.2.0...v0.3.0) (2026-10-29)


### Features

* add site:permissions to hand a site's files back to you ([d772733](https://github.com/winuxio/winux-cli/commit/d77273308bfcd0147c78783c4b3e6cfe11c38441))


### Bug Fixes

* check postgres cluster status with pg_lsclusters without systemd ([2d16eda](https://github.com/winuxio/winux-cli/commit/2d16eda9dae6f6b04be6aae65f757f3b5ff69e30))
* clean errors instead of crashes and silent failures in smaller commands ([c493b2c](https://github.com/winuxio/winux-cli/commit/c493b2c83bee7ca01c37b143419c1038103819ef))
* harden releases, install.sh and self-update ([#13](https://github.com/winuxio/winux-cli/issues/13)) ([469cf6d](https://github.com/winuxio/winux-cli/commit/469cf6d147b65d5c56f67d0e72c9598b1e1a3408))
* keep the drop-database warning visible in project:delete ([f77ea79](https://github.com/winuxio/winux-cli/commit/f77ea792b2c66d3167f751388511366b7a67dd18))
* make project:create scriptable, finish imports, and guard site:php ([46ae63f](https://github.com/winuxio/winux-cli/commit/46ae63f40f0eb47405b3dec6c968a69d32fed486))
* run nginx and PHP-FPM as the winux user, and make setup work without Windows ([207c5bb](https://github.com/winuxio/winux-cli/commit/207c5bbd8434929fc018349093ba4fd8dee62454))
* validate project names and protect databases and nginx on create, import and delete ([#12](https://github.com/winuxio/winux-cli/issues/12)) ([0d409fb](https://github.com/winuxio/winux-cli/commit/0d409fb6f1d09552df75553627160c70ce6d21c2))

## [0.2.0](https://github.com/winuxio/winux-cli/compare/v0.1.1...v0.2.0) (2026-10-29)


### ⚠ BREAKING CHANGES

* modernize winux for current PHP, Postgres and mkcert, with automated releases ([#8](https://github.com/winuxio/winux-cli/issues/8))

### Features

* modernize winux for current PHP, Postgres and mkcert, with automated releases ([#8](https://github.com/winuxio/winux-cli/issues/8)) ([0bb94cf](https://github.com/winuxio/winux-cli/commit/0bb94cf02e6c5361315c9c6f5629bf0015072447))
