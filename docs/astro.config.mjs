// @ts-check
import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import starlightLinksValidator from 'starlight-links-validator';

// Served by GitHub Pages at https://nwidart.github.io/laravel-actuator/.
// Internal links in the content include the /laravel-actuator base.
export default defineConfig({
	site: 'https://nwidart.github.io',
	base: '/laravel-actuator',
	trailingSlash: 'always',
	integrations: [
		starlight({
			title: 'Laravel Actuator',
			description:
				'Production-ready monitoring endpoints for Laravel, modelled on Spring Boot Actuator.',
			logo: { src: './src/assets/logo.svg', replacesTitle: false },
			favicon: '/favicon.svg',
			social: [
				{ icon: 'github', label: 'GitHub', href: 'https://github.com/nWidart/laravel-actuator' },
			],
			editLink: {
				baseUrl: 'https://github.com/nWidart/laravel-actuator/edit/master/docs/',
			},
			lastUpdated: true,
			customCss: ['./src/styles/custom.css'],
			plugins: [starlightLinksValidator()],
			sidebar: [
				{
					label: 'Start here',
					items: [
						{ label: 'Introduction', slug: 'introduction' },
						{ label: 'Installation', slug: 'installation' },
						{ label: 'Tutorial: your first health check', slug: 'tutorial' },
					],
				},
				{
					label: 'How-to guides',
					items: [
						{
							label: 'Endpoints',
							items: [
								{ slug: 'guides/expose-endpoints' },
								{ slug: 'guides/secure-endpoints' },
								{ slug: 'guides/change-the-base-path' },
							],
						},
						{
							label: 'Health',
							items: [
								{ slug: 'guides/configure-health-indicators' },
								{ slug: 'guides/write-a-health-indicator' },
								{ slug: 'guides/define-health-groups' },
								{ slug: 'guides/show-health-details' },
							],
						},
						{
							label: 'Info',
							items: [{ slug: 'guides/add-build-info' }, { slug: 'guides/write-an-info-contributor' }],
						},
						{
							label: 'Deployment',
							items: [
								{ slug: 'guides/kubernetes-probes' },
								{ slug: 'guides/load-balancers-and-containers' },
								{ slug: 'guides/spring-boot-admin' },
							],
						},
						{
							label: 'Testing',
							items: [{ slug: 'guides/test-your-indicators' }],
						},
					],
				},
				{
					label: 'Reference',
					items: [
						{ slug: 'reference/configuration' },
						{
							label: 'Endpoints',
							items: [
								{ slug: 'reference/endpoints/discovery' },
								{ slug: 'reference/endpoints/health' },
								{ slug: 'reference/endpoints/info' },
								{ slug: 'reference/endpoints/env' },
								{ slug: 'reference/endpoints/mappings' },
								{ slug: 'reference/endpoints/scheduledtasks' },
							],
						},
						{ slug: 'reference/health-indicators' },
						{ slug: 'reference/info-contributors' },
						{ slug: 'reference/php-api' },
					],
				},
				{
					label: 'Concepts',
					items: [
						{ slug: 'concepts/spring-boot-compatibility' },
						{ slug: 'concepts/health-model' },
						{ slug: 'concepts/liveness-and-readiness' },
						{ slug: 'concepts/security-model' },
					],
				},
				{
					label: 'Project',
					items: [
						{
							label: 'Changelog',
							link: 'https://github.com/nWidart/laravel-actuator/blob/master/CHANGELOG.md',
							attrs: { target: '_blank' },
						},
						{
							label: 'Contributing',
							link: 'https://github.com/nWidart/laravel-actuator/blob/master/CONTRIBUTING.md',
							attrs: { target: '_blank' },
						},
						{
							label: 'Packagist',
							link: 'https://packagist.org/packages/nwidart/laravel-actuator',
							attrs: { target: '_blank' },
						},
					],
				},
			],
		}),
	],
});
