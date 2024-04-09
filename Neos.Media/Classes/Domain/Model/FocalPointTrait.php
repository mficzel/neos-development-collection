<?php
namespace Neos\Media\Domain\Model;

/*
 * This file is part of the Neos.Media package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\ORM\Mapping as ORM;

/**
 * Trait for methods regarding the focal point of an asset
 */
trait FocalPointTrait
{
    /**
     * @var integer
     * @ORM\Column(nullable=true)
     */
    protected ?int $focalPointX;

    /**
     * @var integer
     * @ORM\Column(nullable=true)
     */
    protected ?int $focalPointY;

    /**
     * Horizontal position of the focal point
     * @return integer
     */
    public function getFocalPointX(): ?int
    {
        return $this->focalPointX;
    }

    /**
     * Vertical position of the focal point
     * @return integer
     */
    public function getFocalPointY(): ?int
    {
        return $this->focalPointY;
    }

    /**
     * Does the asset have a focal point
     */
    public function hasFocalPoint(): bool
    {
        return ($this->focalPointX !== null && $this->focalPointY !== null);
    }
}
