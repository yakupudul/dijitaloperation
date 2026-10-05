<?php

namespace App\Mcp\Tools;

use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description(<<<'TEXT'
Site kurulumu: up to 25 operations on a WordPress site, in order. In the site's "doğrudan" mode they are applied to the live site at once and one result per operation comes back; in "onaylı" mode the build waits (status awaiting_approval) until the operator approves or rejects it in MoxDOP; follow it with site-builds. Every build is in the site's log and the operator can undo it there. Use it only for what the operator asked. Every operation except acf_import, menu and settings needs a `ref` (letters, digits, - _ . :): the same ref again updates instead of duplicating, and later values may point at it with "ref:<ref>" (parent, featured_image, menu item ref, ACF values, Elementor image id / url, link url).
- {op:"acf_import", items:[ACF export objects]}: field groups (key group_…), post types (post_type_…), taxonomies (taxonomy_…), options pages, exactly the ACF › Tools › Export JSON. A new post type exists from the next call: create its posts in a later build-site call.
- {op:"post", ref, post_type:"page", title, slug, status:"draft"|"publish"|"pending"|"private" (new posts default to draft), content (HTML), excerpt, parent, menu_order, template, featured_image, terms:{taxonomy:[names]}, acf:{field_name: value}, elementor:[elements] or a Templates › Import object, seo:{title, description}}.
- {op:"elementor_template", ref, type:"header"|"footer"|"section"|"container"|"page"|"single"|"archive"|"popup"|…, title, template:{content:[…], page_settings:{…}} (the Templates › Import JSON), conditions:["include/general"]} (theme-builder types and conditions need Elementor Pro).
- {op:"media", ref, url:"https://…" or data_base64 + filename, alt, title}: an image (at most 10 MB).
- {op:"menu", name, items:[{title, ref | post_id | url, children:[…]}], location}: replaces the menu's items; location from inspect-site theme.menu_locations.
- {op:"settings", values:{blogname, blogdescription, show_on_front, page_on_front, page_for_posts, permalink_structure, elementor_cpt_support}}.
- {op:"trash", ref}: removes something a build made.
Read inspect-site first. Theme and plugin files are never touched.
TEXT)]
class BuildSite extends Tool
{
    public function __construct(private readonly WordPressSiteBuilder $builder) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site_id' => ['required', 'integer'],
            'operations' => ['required', 'array', 'min:1', 'max:25'],
            'operations.*' => ['array'],
            'operations.*.op' => ['required', 'string', 'in:acf_import,post,elementor_template,media,menu,settings,trash'],
        ]);
        try {
            return Response::json($this->builder->build((int) $validated['site_id'], array_values($request->get('operations'))));
        } catch (Throwable $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->integer()->description('site_id from list-sites.')->required(),
            'operations' => $schema->array()->items($schema->object())->description('Operations in order (see the tool description).')->required(),
        ];
    }
}
