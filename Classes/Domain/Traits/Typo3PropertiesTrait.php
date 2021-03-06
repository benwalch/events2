<?php
declare(strict_types = 1);
namespace JWeiland\Events2\Domain\Traits;

/*
 * This file is part of the events2 project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

trait Typo3PropertiesTrait
{
    /**
     * @var \DateTime
     */
    protected $crdate;

    /**
     * @var \DateTime
     */
    protected $tstamp;

    /**
     * @var int
     */
    protected $sysLanguageUid = 0;

    /**
     * @var int
     */
    protected $l10nParent= 0;

    /**
     * @var \DateTime
     */
    protected $starttime;

    /**
     * @var \DateTime
     */
    protected $endtime;

    /**
     * @var bool
     */
    protected $hidden = false;

    /**
     * @var bool
     */
    protected $deleted = false;

    /**
     * @var int
     */
    protected $cruserId = 0;

    /**
     * @return \DateTime|null
     */
    public function getCrdate()
    {
        return $this->crdate;
    }

    public function setCrdate(\DateTime $crdate = null)
    {
        $this->crdate = $crdate;
    }

    /**
     * @return \DateTime|null
     */
    public function getTstamp()
    {
        return $this->tstamp;
    }

    public function setTstamp(\DateTime $tstamp = null)
    {
        $this->tstamp = $tstamp;
    }

    public function getSysLanguageUid(): int
    {
        return $this->sysLanguageUid;
    }

    public function setSysLanguageUid(int $sysLanguageUid)
    {
        $this->sysLanguageUid = $sysLanguageUid;
    }

    public function getL10nParent(): int
    {
        return $this->l10nParent;
    }

    public function setL10nParent(int $l10nParent)
    {
        $this->l10nParent = $l10nParent;
    }

    /**
     * @return \DateTime|null
     */
    public function getStarttime()
    {
        return $this->starttime;
    }

    public function setStarttime(\DateTime $starttime = null)
    {
        $this->starttime = $starttime;
    }

    /**
     * @return \DateTime|null
     */
    public function getEndtime()
    {
        return $this->endtime;
    }

    public function setEndtime(\DateTime $endtime = null)
    {
        $this->endtime = $endtime;
    }

    public function getHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden)
    {
        $this->hidden = $hidden;
    }

    public function getDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted)
    {
        $this->deleted = $deleted;
    }

    public function getCruserId(): int
    {
        return $this->cruserId;
    }

    public function setCruserId(int $cruserId)
    {
        $this->cruserId = $cruserId;
    }
}
