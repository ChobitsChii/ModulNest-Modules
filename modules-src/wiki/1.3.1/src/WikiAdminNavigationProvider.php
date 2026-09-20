<?php
declare(strict_types=1);
namespace ModulNest\Wiki;
use Modulon\Core\AdminNavigationProviderInterface;
final class WikiAdminNavigationProvider implements AdminNavigationProviderInterface { public function moduleKey(): string { return 'modulnest.wiki'; } public function items(string $currentPath): array { return [['key'=>'modulnest.wiki','label'=>'Wiki','url'=>'/admin/wiki','description'=>'Wiki verwalten','is_active'=>rtrim($currentPath,'/')==='/admin/wiki','sort_order'=>900]]; } }
