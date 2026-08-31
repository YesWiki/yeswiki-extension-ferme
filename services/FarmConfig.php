<?php

namespace YesWiki\Ferme\Service;

use YesWiki\Wiki;

class FarmConfig
{
    protected $wiki;
    protected $files;

    protected $wikiConfigCache = [];

    public function __construct(Wiki $wiki, FileSystem $files)
    {
        $this->wiki = $wiki;
        $this->files = $files;
        $this->init();
    }

    public function init(): void
    {
        $this->applyRootDefaults();
        $this->applyExtraDefaults();
        $this->applyThemeDefaults();
        $this->applyAclDefaults();
        $this->applyModelDefaults();
        $this->applyWikiAdminDefaults();
    }

    public function rootFolder(): string
    {
        $folder = $this->wiki->config['yeswiki-farm-root-folder'] ?? '.';

        return $folder !== '' ? $folder : '.';
    }

    public function rootUrl(): string
    {
        return $this->wiki->config['yeswiki-farm-root-url'] ?? '';
    }

    public function basePath(): string
    {
        return $this->rootFolder() === '.'
            ? getcwd()
            : getcwd() . DIRECTORY_SEPARATOR . $this->rootFolder();
    }

    public function wikiDir(string $folder): string
    {
        return $this->files->getAbsolutePath($this->basePath() . DIRECTORY_SEPARATOR . $folder);
    }

    public function wikiConfigFile(string $folder): string
    {
        return $this->wikiDir($folder) . 'wakka.config.php';
    }

    public function readWikiConfig(string $folder): array
    {
        if (array_key_exists($folder, $this->wikiConfigCache)) {
            return $this->wikiConfigCache[$folder];
        }

        $wakkaConfig = [];
        $path = $this->wikiConfigFile($folder);
        if (file_exists($path)) {
            include $path;
        }

        return $this->wikiConfigCache[$folder] = $wakkaConfig;
    }

    public function theme($index): array
    {
        return $this->wiki->config['yeswiki-farm-themes'][$index];
    }

    public function acl($index): array
    {
        return $this->wiki->config['yeswiki-farm-acls'][$index];
    }

    public function getModelLabels(): array
    {
        $models = [];
        foreach ($this->wiki->config['yeswiki-farm-models'] as $model) {
            if ($model != 'default-content') {
                $json = \json_decode(\file_get_contents('custom/wiki-models/' . $model . '/infos.json'), true);
            } else {
                $json = [];
                $json['label'] = _t('FERME_BASIC_INSTALL');
            }
            $models[$model] = $json['label'];
        }

        return $models;
    }

    private function applyRootDefaults(): void
    {
        if (!isset($this->wiki->config['yeswiki-farm-root-url'])) {
            $this->wiki->config['yeswiki-farm-root-url'] = str_replace(
                ['wakka.php?wiki=', '?'],
                '',
                $this->wiki->config['base_url']
            );
            $this->wiki->config['yeswiki-farm-root-folder'] = '.';
        } elseif (!isset($this->wiki->config['yeswiki-farm-root-folder'])) {
            throw new \RuntimeException('Il faut indiquer le chemin relatif des wikis avec la valeur "yeswiki-farm-root-folder" dans le fichier de configuration.');
        }
    }

    private function applyExtraDefaults(): void
    {
        if (
            !isset($this->wiki->config['yeswiki-farm-extra-themes'])
            || !is_array($this->wiki->config['yeswiki-farm-extra-themes'])
        ) {
            $this->wiki->config['yeswiki-farm-extra-themes'] = [];
        }

        if (
            !isset($this->wiki->config['yeswiki-farm-extra-tools'])
            || !is_array($this->wiki->config['yeswiki-farm-extra-tools'])
        ) {
            $this->wiki->config['yeswiki-farm-extra-tools'] = [];
        }

        if (is_null($this->wiki->config['yeswiki_symlinked_files'])) {
            $this->wiki->config['yeswiki_symlinked_files'] = [];
        }

        if (!isset($this->wiki->config['yeswiki-farm-bg-img'])) {
            $this->wiki->config['yeswiki-farm-bg-img'] = '';
        }
    }

    private function applyThemeDefaults(): void
    {
        if (
            !isset($this->wiki->config['yeswiki-farm-themes'])
            or !is_array($this->wiki->config['yeswiki-farm-themes'])
        ) {
            $this->wiki->config['yeswiki-farm-themes'][0]['label'] = 'Margot (theme de base)';
            $this->wiki->config['yeswiki-farm-themes'][0]['screenshot'] = 'margot.jpg';
            $this->wiki->config['yeswiki-farm-themes'][0]['theme'] = THEME_PAR_DEFAUT;
            $this->wiki->config['yeswiki-farm-themes'][0]['squelette'] = SQUELETTE_PAR_DEFAUT;
            $this->wiki->config['yeswiki-farm-themes'][0]['style'] = CSS_PAR_DEFAUT;

            return;
        }

        foreach ($this->wiki->config['yeswiki-farm-themes'] as $key => $theme) {
            $this->validateTheme($key, $theme);
        }
    }

    private function validateTheme($key, array $theme): void
    {
        if (!isset($theme['label']) or empty($theme['label'])) {
            throw new \RuntimeException('Au moins un label pour les themes de la ferme n\'a pas été bien renseigné.');
        }

        if (!isset($theme['screenshot']) || $theme['screenshot'] === '') {
            throw new \RuntimeException('Au moins un screenshot pour les themes de la ferme n\'a pas été bien renseigné.');
        }
        if ($theme['screenshot'] !== false && !is_file('tools/ferme/screenshots/' . $theme['screenshot'])) {
            $this->wiki->config['yeswiki-farm-themes'][$key]['screenshot'] = false;
        }

        if (!isset($theme['theme']) or empty($theme['theme'])) {
            throw new \RuntimeException('Au moins un theme pour les themes de la ferme n\'a pas été bien renseigné.');
        } elseif ($this->themeFileMissing($theme['theme'], '')) {
            throw new \RuntimeException('Le dossier "themes/' . $theme['theme'] . '" n\'a pas été trouvé.');
        }

        if (!isset($theme['squelette']) or empty($theme['squelette'])) {
            throw new \RuntimeException('Au moins un squelette pour les themes de la ferme n\'a pas été bien renseigné.');
        } elseif ($this->themeFileMissing($theme['theme'], 'squelettes/' . $theme['squelette'])) {
            throw new \RuntimeException('Le squelette "themes/' . $theme['theme'] . '/squelettes/' . $theme['squelette'] . '" n\'a pas été trouvé.');
        }

        if (!isset($theme['style']) or empty($theme['style'])) {
            throw new \RuntimeException('Au moins un style css pour les themes de la ferme n\'a pas été bien renseigné.');
        } elseif ($this->themeFileMissing($theme['theme'], 'styles/' . $theme['style'])) {
            throw new \RuntimeException('Le style css "themes/' . $theme['theme'] . '/styles/' . $theme['style'] . '" n\'a pas été trouvé.');
        }
    }

    private function themeFileMissing(string $theme, string $relative): bool
    {
        $path = 'themes/' . $theme . ($relative === '' ? '' : '/' . $relative);
        if ($relative === '' ? is_dir($path) : is_file($path)) {
            return false;
        }

        if ($theme !== 'yeswiki') {
            return false;
        }

        $fallback = 'tools/templates/' . $path;

        return $relative === '' ? !is_dir($fallback) : !is_file($fallback);
    }

    private function applyAclDefaults(): void
    {
        if (
            !isset($this->wiki->config['yeswiki-farm-acls'])
            or !is_array($this->wiki->config['yeswiki-farm-acls'])
        ) {
            $this->wiki->config['yeswiki-farm-acls'][0]['label'] = 'Wiki ouvert';
            $this->wiki->config['yeswiki-farm-acls'][0]['read'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['write'] = '*';
            $this->wiki->config['yeswiki-farm-acls'][0]['comments'] = 'comments-closed';

            return;
        }

        $required = [
            'label' => 'Au moins un label pour les acls de la ferme n\'a pas été bien renseigné.',
            'read' => 'Au moins un droit en lecture (read) n\'a pas été bien renseigné.',
            'write' => 'Au moins un droit en écriture (write) n\'a pas été bien renseigné.',
            'comments' => 'Au moins un droit des commentaires (comments) n\'a pas été bien renseigné.',
        ];
        foreach ($this->wiki->config['yeswiki-farm-acls'] as $acls) {
            foreach ($required as $key => $message) {
                if (!isset($acls[$key]) or empty($acls[$key])) {
                    throw new \RuntimeException($message);
                }
            }
        }
    }

    private function applyModelDefaults(): void
    {
        if (
            !isset($this->wiki->config['yeswiki-farm-models'])
            or !is_array($this->wiki->config['yeswiki-farm-models'])
        ) {
            $this->wiki->config['yeswiki-farm-models'][] = 'default-content';

            return;
        }

        foreach ($this->wiki->config['yeswiki-farm-models'] as $key => $folder) {
            if ($folder == 'default-content') {
                continue;
            }
            if (!is_dir('custom/wiki-models/' . $folder)) {
                unset($this->wiki->config['yeswiki-farm-models'][$key]);
                trigger_error('le dossier "custom/wiki-models/' . $folder . '" ne semble pas exister.');
            } elseif (!is_file('custom/wiki-models/' . $folder . '/default-content.sql')) {
                unset($this->wiki->config['yeswiki-farm-models'][$key]);
                trigger_error('Le fichier sql "custom/wiki-models/' . $folder . '/default-content.sql" n\'a pas été trouvé.');
            }
        }
    }

    private function applyWikiAdminDefaults(): void
    {
        $defaults = [

            'yeswiki-farm-create-user' => false,

            'yeswiki-farm-default-WikiAdmin' => 'WikiAdmin',

            'yeswiki-farm-password-WikiAdmin' => '',

            'yeswiki-farm-email-WikiAdmin' => 'bf_mail',

            'yeswiki-farm-prefix' => 'yeswiki_',

            'yeswiki-farm-admin-name' => '',
            'yeswiki-farm-admin-pass' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->wiki->config[$key])) {
                $this->wiki->config[$key] = $value;
            }
        }

        if (!isset($this->wiki->config['yeswiki-farm-homepage'])) {
            $this->wiki->config['yeswiki-farm-homepage'] = $this->wiki->config['root_page'];
        }
    }
}
