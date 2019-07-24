<?php
/* Icinga Web 2 | (c) 2014 Icinga Development Team | GPLv2+ */

namespace Icinga\Forms\Security;

use Icinga\Application\Icinga;
use Icinga\Application\Modules\Manager;
use Icinga\Data\Filter\Filter;
use Icinga\Forms\ConfigForm;
use Icinga\Forms\RepositoryForm;
use Icinga\Util\StringHelper;
use Zend_Form_Element;

/**
 * Form for managing roles
 */
class RoleForm extends RepositoryForm
{
    /**
     * The name to use instead of `*`
     */
    const WILDCARD_NAME = 'allAndEverything';

    /**
     * Provided permissions by currently installed modules
     *
     * @var array
     */
    protected $providedPermissions;

    /**
     * Provided restrictions by currently installed modules
     *
     * @var array
     */
    protected $providedRestrictions;

    public function init()
    {
        $helper = new Zend_Form_Element('bogus');

        $this->providedPermissions['application'] = [
            $helper->filterName('application/share/navigation') => [
                'name'          => 'application/share/navigation',
                'description'   => $this->translate('Allow to share navigation items')
            ],
            $helper->filterName('application/stacktraces') => [
                'name'          => 'application/stacktraces',
                'description'   => $this->translate(
                    'Allow to adjust in the preferences whether to show stacktraces'
                )
            ],
            $helper->filterName('application/log') => [
                'name'          => 'application/log',
                'description'   => $this->translate('Allow to view the application log')
            ],
            $helper->filterName('admin') => [
                'name'          => 'admin',
                'description'   => $this->translate(
                    'Grant admin permissions, e.g. manage announcements'
                )
            ],
            $helper->filterName('config/*') => [
                'name'          => 'config/*',
                'description'   => $this->translate('Allow config access')
            ]
        ];

        $this->providedRestrictions['application'] = [
            $helper->filterName('application/share/users') => [
                'name'          => 'application/share/users',
                'description'   => $this->translate(
                    'Restrict which users this role can share items and information with'
                )
            ],
            $helper->filterName('application/share/groups') => [
                'name'          => 'application/share/groups',
                'description'   => $this->translate(
                    'Restrict which groups this role can share items and information with'
                )
            ]
        ];

        $mm = Icinga::app()->getModuleManager();
        foreach ($mm->listInstalledModules() as $moduleName) {
            $modulePermission = Manager::MODULE_PERMISSION_NS . $moduleName;
            $this->providedPermissions[$moduleName][$helper->filterName($modulePermission)] = [
                'isUsagePerm'   => true,
                'name'          => $modulePermission,
                'label'         => $this->translate('General Module Access'),
                'description'   => sprintf($this->translate('Allow access to module %s'), $moduleName)
            ];

            $module = $mm->getModule($moduleName, false);
            $permissions = $module->getProvidedPermissions();

            $this->providedPermissions[$moduleName][$helper->filterName($moduleName . '/*')] = [
                'isFullPerm'    => true,
                'name'          => $moduleName . '/*',
                'label'         => $this->translate('Full Module Access')
            ];

            foreach ($permissions as $permission) {
                /** @var object $permission */
                $this->providedPermissions[$moduleName][$helper->filterName($permission->name)] = [
                    'name'          => $permission->name,
                    'description'   => $permission->description
                ];
            }

            foreach ($module->getProvidedRestrictions() as $restriction) {
                $this->providedRestrictions[$moduleName][$helper->filterName($restriction->name)] = [
                    'name'          => $restriction->name,
                    'description'   => $restriction->description
                ];
            }
        }
    }

    protected function createFilter()
    {
        return Filter::where('name', $this->getIdentifier());
    }

    public function createInsertElements(array $formData = array())
    {
        $this->addElement(
            'text',
            'name',
            [
                'required'      => true,
                'label'         => $this->translate('Role Name'),
                'description'   => $this->translate('The name of the role')
            ]
        );
        $this->addElement(
            'textarea',
            'users',
            [
                'label'         => $this->translate('Users'),
                'description'   => $this->translate('Comma-separated list of users that are assigned to the role')
            ]
        );
        $this->addElement(
            'textarea',
            'groups',
            [
                'label'         => $this->translate('Groups'),
                'description'   => $this->translate('Comma-separated list of groups that are assigned to the role')
            ]
        );
        $this->addElement(
            'checkbox',
            self::WILDCARD_NAME,
            [
                'autosubmit'    => true,
                'label'         => $this->translate('Administrative Access'),
                'description'   => $this->translate('Everything is allowed')
            ]
        );

        if (! isset($formData[self::WILDCARD_NAME]) || ! $formData[self::WILDCARD_NAME]) {
            foreach ($this->providedPermissions as $moduleName => $permissionList) {
                $this->sortPermissions($permissionList);

                $elements = [];
                $hasFullPerm = false;
                foreach ($permissionList as $name => $spec) {
                    $elements[] = $name;
                    $this->addElement(
                        'checkbox',
                        $name,
                        [
                            'ignore'        => isset($spec['isUsagePerm']) ? false : $hasFullPerm,
                            'autosubmit'    => isset($spec['isFullPerm']),
                            'disabled'      => $hasFullPerm ?: null,
                            'value'         => $hasFullPerm,
                            'label'         => isset($spec['label']) ? $spec['label'] : $spec['name'],
                            'description'   => isset($spec['description']) ? $spec['description'] : $spec['name']
                        ]
                    );
                    if (isset($spec['isFullPerm'])) {
                        $hasFullPerm = isset($formData[$name]) && $formData[$name];
                    }
                }

                if (isset($this->providedRestrictions[$moduleName])) {
                    foreach ($this->providedRestrictions[$moduleName] as $name => $spec) {
                        $elements[] = $name;
                        $this->addElement(
                            'text',
                            $name,
                            [
                                'label'         => $spec['name'],
                                'description'   => $spec['description']
                            ]
                        );
                    }
                }

                $this->addDisplayGroup($elements, $moduleName . '_elements', [
                    'legend'        => $moduleName !== 'application'
                        ? sprintf($this->translate('Module: %s'), $moduleName)
                        :  'Icinga Web 2',
                    'decorators'    => [
                        'FormElements',
                        ['Fieldset', ['class' => 'collapsible']]
                    ]
                ]);
            }
        } else {
            // Previously it was possible to define restrictions for super users, so make sure
            // to not remove any restrictions which were set before the enforced separation
            foreach ($this->providedRestrictions as $restrictionList) {
                foreach ($restrictionList as $name => $_) {
                    $this->addElement('hidden', $name);
                }
            }
        }
    }

    protected function createDeleteElements(array $formData)
    {
    }

    public function fetchEntry()
    {
        $role = parent::fetchEntry();
        if ($role === false) {
            return false;
        }

        $values = [
            'name'              => $role->name,
            'users'             => $role->users,
            'groups'            => $role->groups,
            self::WILDCARD_NAME => $role->permissions === '*'
        ];

        if (! empty($role->permissions) && $role->permissions !== '*') {
            $permissions = StringHelper::trimSplit($role->permissions);
            foreach ($this->providedPermissions as $moduleName => $permissionList) {
                foreach ($permissionList as $name => $spec) {
                    if (in_array($spec['name'], $permissions, true)) {
                        $values[$name] = 1;
                    }
                }
            }
        }

        foreach ($this->providedRestrictions as $moduleName => $restrictionList) {
            foreach ($restrictionList as $name => $spec) {
                if (isset($role->{$spec['name']})) {
                    $values[$name] = $role->{$spec['name']};
                }
            }
        }

        return (object) $values;
    }

    public function getValues($suppressArrayNotation = false)
    {
        $values = parent::getValues($suppressArrayNotation);

        foreach ($this->providedRestrictions as $moduleName => $restrictionList) {
            foreach ($restrictionList as $name => $spec) {
                if (isset($values[$name])) {
                    $values[$spec['name']] = $values[$name];
                    unset($values[$name]);
                }
            }
        }

        $permissions = [];
        if (isset($values[self::WILDCARD_NAME]) && $values[self::WILDCARD_NAME]) {
            $permissions[] = '*';
        } else {
            foreach ($this->providedPermissions as $moduleName => $permissionList) {
                foreach ($permissionList as $name => $spec) {
                    if (isset($values[$name]) && $values[$name]) {
                        $permissions[] = $spec['name'];
                    }

                    unset($values[$name]);
                }
            }
        }

        unset($values[self::WILDCARD_NAME]);
        $values['permissions'] = join(',', $permissions);
        return ConfigForm::transformEmptyValuesToNull($values);
    }

    protected function getInsertMessage($success)
    {
        return $success ? $this->translate('Role created') : $this->translate('Role creation failed');
    }

    protected function getUpdateMessage($success)
    {
        return $success ? $this->translate('Role updated') : $this->translate('Role update failed');
    }

    protected function getDeleteMessage($success)
    {
        return $success ? $this->translate('Role removed') : $this->translate('Role removal failed');
    }

    protected function sortPermissions(& $permissions)
    {
        return uasort($permissions, function ($a, $b) {
            if (isset($a['isUsagePerm'])) {
                return isset($b['isFullPerm']) ? 1 : -1;
            } elseif (isset($b['isUsagePerm'])) {
                return isset($a['isFullPerm']) ? -1 : 1;
            }

            $aParts = explode('/', $a['name']);
            $bParts = explode('/', $b['name']);

            do {
                $a = array_shift($aParts);
                $b = array_shift($bParts);
            } while ($a === $b);

            return strnatcmp($a, $b);
        });
    }
}
