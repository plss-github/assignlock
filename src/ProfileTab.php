<?php

namespace GlpiPlugin\Assignlock;

use CommonGLPI;
use Html;
use Profile;
use ProfileRight;
use Session;

/**
 * Aba "Trava de atribuição" no cadastro de perfil (Administração > Perfis).
 * O direito é um registro comum de glpi_profilerights, então acompanha cópia de perfil etc.
 */
final class ProfileTab extends CommonGLPI
{
    /** Direito: com READ, o perfil pode atribuir técnicos de qualquer grupo. */
    public const RIGHT = 'plugin_assignlock_bypass';

    public static function getTypeName($nb = 0): string
    {
        return __('Trava de atribuição', 'assignlock');
    }

    public static function currentProfileIsExempt(): bool
    {
        return (bool) Session::haveRight(self::RIGHT, READ);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getID() > 0) {
            return self::createTabEntry(self::getTypeName(), 0, null, 'ti ti-lock');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getID() > 0) {
            self::showForm((int) $item->getID());
        }
        return true;
    }

    private static function showForm(int $profiles_id): void
    {
        global $DB;

        $can_edit = Session::haveRight('profile', UPDATE);

        $current = false;
        foreach ($DB->request([
            'FROM'  => ProfileRight::getTable(),
            'WHERE' => ['profiles_id' => $profiles_id, 'name' => self::RIGHT],
        ]) as $row) {
            $current = ((int) $row['rights'] & READ) > 0;
        }

        if ($can_edit) {
            echo '<form method="post" action="' . htmlescape(plugin_assignlock_form_url()) . '">';
        }
        echo '<div class="card m-3"><div class="card-header"><h3 class="card-title">'
            . htmlescape(self::getTypeName()) . '</h3></div><div class="card-body">';
        echo '<p class="text-muted">' . htmlescape(__(
            'Por padrão, um técnico só pode ser atribuído a um chamado se fizer parte de algum grupo atribuído a ele (chamados sem grupo atribuído são livres).',
            'assignlock'
        )) . '</p>';
        echo '<div class="form-check"><label class="form-check-label">'
            . '<input class="form-check-input" type="checkbox" name="bypass" value="1"'
            . ($current ? ' checked' : '') . ($can_edit ? '' : ' disabled') . '> '
            . htmlescape(__('Pode se atribuir e atribuir técnicos em chamados de outros grupos', 'assignlock'))
            . '</label></div>';
        echo '</div>';

        if ($can_edit) {
            echo Html::hidden('profiles_id', ['value' => $profiles_id]);
            echo '<div class="card-footer"><button type="submit" name="update" value="1" class="btn btn-primary">'
                . htmlescape(_x('button', 'Save')) . '</button></div></div>';
            Html::closeForm();
        } else {
            echo '</div>';
        }
    }
}
