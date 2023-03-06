<?php

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\Neos\Domain\Repository;

use Neos\Neos\Domain\Model\EditPreviewMode;
use Neos\Flow\Annotations as Flow;

class EditPreviewModeRepository
{
    #[Flow\InjectConfiguration(path:"userInterface.defaultEditPreviewMode")]
    protected string $defaultEditPreviewMode;

    #[Flow\InjectConfiguration(path:"userInterface.editPreviewModes")]
    protected array $editPreviewModeConfigurations;

    public function findDefault(): EditPreviewMode
    {
        return EditPreviewMode::fromNameAndConfiguration($this->defaultEditPreviewMode, $this->editPreviewModeConfigurations[$this->defaultEditPreviewMode]);
    }

    public function findByName(string $name): EditPreviewMode
    {
        return EditPreviewMode::fromNameAndConfiguration($name, $this->editPreviewModeConfigurations[$name]);
    }
}

