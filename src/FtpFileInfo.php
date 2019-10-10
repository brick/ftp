<?php

declare(strict_types=1);

namespace Brick\Ftp;

/**
 * Holds information about a remote file or directory on a FTP server.
 *
 * The file name is always available; other fields will only be available if the server supports the MLSD command.
 */
class FtpFileInfo
{
    /**
     * The file name.
     *
     * @var string
     */
    public $name;

    /**
     * Whether this is a directory, null if not available.
     *
     * @var bool|null
     */
    public $isDir = null;

    /**
     * The file size in bytes, null if not available.
     *
     * @var int|null
     */
    public $size = null;

    /**
     * The creation date/time, null if not available.
     *
     * Format: YYYYMMDDHHMMSS or YYYYMMDDHHMMSS.sss
     *
     * @var string|null
     */
    public $creationTime = null;

    /**
     * The last modification date/time, null if not available.
     *
     * Format: YYYYMMDDHHMMSS or YYYYMMDDHHMMSS.sss
     *
     * @var string|null
     */
    public $lastModificationTime = null;

    /**
     * A unique id for the file/directory, null if not available.
     *
     * @var string|null
     */
    public $uniqueId = null;
}
