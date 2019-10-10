<?php

namespace Brick\Ftp\FtpClient;

use TypeError;

class FtpClient
{
    /**
     * The open FTP connection, or null if not connected.
     *
     * @var resource|null
     */
    private $conn;

    /**
     * Opens an FTP connection.
     *
     * @param string $host    The host to connect to.
     * @param int    $port    The port to connect to.
     * @param bool   $ssl     Whether to use an explicit SSL-FTP connection.
     * @param int    $timeout The timeout in seconds.
     *
     * @return void
     *
     * @throws FtpException If unable to connect to the FTP server.
     */
    public function connect(string $host, int $port = 21, bool $ssl = false, int $timeout = 90) : void
    {
        if ($this->conn) {
            throw new FtpException('A connection is already open. Please use close() before connect().');
        }

        if ($ssl) {
            $conn = $this->call(true, 'ftp_ssl_connect', $host, $port, $timeout);
        } else {
            $conn = $this->call(true, 'ftp_connect', $host, $port, $timeout);
        }

        if ($conn === false) {
            throw new FtpException("Unable to connect to $host on port $port");
        }

        $this->conn = $conn;
    }

    /**
     * Closes the FTP connection.
     *
     * @return void
     *
     * @throws FtpException If no connection is currently open.
     */
    public function close() : void
    {
        $this->throwIfNotConnected();
        $this->call(false, 'ftp_close', $this->conn);

        $this->conn = null;
    }

    /**
     * Logs in to the FTP connection.
     *
     * @param string $username
     * @param string $password
     *
     * @return void
     *
     * @throws FtpException If not connected, credentials are invalid, or an error occurs.
     */
    public function login(string $username, string $password) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_login', $this->conn, $username, $password);

        if (! $success) {
            throw new FtpException('Unable to log in with given credentials.');
        }
    }

    /**
     * Turns passive mode on or off.
     *
     * In passive mode, data connections are initiated by the client, rather than by the server.
     * It may be needed if the client is behind a firewall.
     *
     * @param bool $passive
     *
     * @return void
     *
     * @throws FtpException If not connected, or setting passive mode fails.
     */
    public function setPassive(bool $passive) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_pasv', $this->conn, $passive);

        if (! $success) {
            throw new FtpException(sprintf('Unable to set passive mode to %s.', var_export($passive, true)));
        }
    }

    /**
     * Returns the current directory name.
     *
     * @return string
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function getWorkingDirectory() : string
    {
        $this->throwIfNotConnected();

        $directory = $this->call(true, 'ftp_pwd', $this->conn);

        if ($directory === false) {
            throw new FtpException('Unable to get working directory.');
        }

        return $directory;
    }

    /**
     * Changes the current directory.
     *
     * @param string $directory The target directory path.
     *
     * @return void
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function setWorkingDirectory(string $directory) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_chdir', $this->conn, $directory);

        if (! $success) {
            throw new FtpException('Unable to get change working directory.');
        }
    }

    /**
     * Returns a list of files in the given directory.
     *
     * If the FTP server supports the MLSD command, each FtpFileInfo will contain additional information.
     * If the server does not support this command, each FtpFileInfo will only contain the file name.
     *
     * This method ignores "." and ".." directories, if returned by the server.
     *
     * @todo we could attempt parsing ftp_rawlist() here, before falling back to ftp_nlist();
     *       this would require quite a lot of work to identify the formats for many different platforms though.
     *
     * @param string $directory The target directory path.
     *
     * @return FtpFileInfo[]
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function listDirectory(string $directory) : array
    {
        $this->throwIfNotConnected();

        try {
            $records = $this->call(true, 'ftp_mlsd', $this->conn, $directory);
        } catch (FtpException $e) {
            if (substr($e->getMessage(), 0, 3) === '500') {
                // MLSD command not supported
                return $this->basicListDirectory($directory);
            } else {
                // Other error, re-throw
                throw $e;
            }
        }

        if ($records === false) {
            throw new FtpException('Unable to get file list.');
        }

        $result = [];

        foreach ($records as $facts) {
            $fileInfo = $this->mlsdFactsToFileInfo($facts);

            if ($fileInfo !== null) {
                $result[] = $fileInfo;
            }
        }

        return $result;
    }

    /**
     * Converts MLSD facts to a FtpFileInfo object.
     *
     * If the file should be skipped, null is returned.
     *
     * @param array $facts
     *
     * @return FtpFileInfo|null
     */
    private function mlsdFactsToFileInfo(array $facts) : ?FtpFileInfo
    {
        $name = $facts['name'];

        if ($name === '.' || $name === '..') {
            return null;
        }

        $fileInfo = new FtpFileInfo;
        $fileInfo->name = $name;

        if (isset($facts['size'])) {
            $fileInfo->size = (int) $facts['size'];
        }

        if (isset($facts['create'])) {
            $fileInfo->creationTime = $facts['create'];
        }

        if (isset($facts['modify'])) {
            $fileInfo->lastModificationTime = $facts['modify'];
        }

        if (isset($facts['unique'])) {
            $fileInfo->uniqueId = $facts['unique'];
        }

        if (isset($facts['type'])) {
            switch ($facts['type']) {
                case 'file':
                    $fileInfo->isDir = false;
                    break;

                case 'dir':
                    $fileInfo->isDir = true;
                    break;

                case 'cdir': // the listed directory
                case 'pdir': // a parent directory
                    // these should already be excluded with "." and "..", but this extra check is free
                    return null;

                default:
                    // unknown type; leaving the default:
                    // $fileInfo->isDir = null;
            }
        }

        return $fileInfo;
    }

    /**
     * Returns a basic directory listing (file name only).
     *
     * This method ignores "." and ".." directories, if returned by the server.
     *
     * @param string $directory
     *
     * @return FtpFileInfo[]
     *
     * @throws FtpException If an error occurs.
     */
    private function basicListDirectory(string $directory) : array
    {
        $files = $this->call(true, 'ftp_nlist', $this->conn, $directory);

        if ($files === false) {
            throw new FtpException('Unable to get file list.');
        }

        $result = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fileInfo = new FtpFileInfo;
            $fileInfo->name = $file;

            $result[] = $fileInfo;
        }

        return $result;
    }

    /**
     * Lists all files in the given directory and its subdirectories.
     *
     * Directories and files with unknown types are not returned in the output. As a result, if the server does not
     * support the MLSD command (allowing to get file info), the result will always be an empty array.
     *
     * The resulting array of FtpFileInfo objects is indexed by path relative to the given directory,
     * for example given these files:
     *
     * /a/b/c/foo.txt
     * /a/b/c/d/bar.txt
     *
     * recursivelyListFilesInDirectory('/a/b') would return [
     *   'c/foo.txt' => (FtpFileInfo object),
     *   'c/d/bar.txt' => (FtpFileInfo object)
     * ]
     *
     * @param string $directory
     *
     * @return FtpFileInfo[]
     *
     * @throws FtpException
     */
    public function recursivelyListFilesInDirectory(string $directory) : array
    {
        $result = $this->doRecursivelyListFilesInDirectory($directory);

        $trimLength = strlen($directory) + 1;

        $newResult = [];

        foreach ($result as $path => $file) {
            $newPath = substr($path, $trimLength);
            $newResult[$newPath] = $file;
        }

        return $newResult;
    }

    /**
     * Internal method for recursivelyListFilesInDirectory()
     *
     * @param string $directory
     *
     * @return FtpFileInfo[]
     *
     * @throws FtpException
     */
    private function doRecursivelyListFilesInDirectory(string $directory) : array
    {
        $result = [];

        $files = $this->listDirectory($directory);

        foreach ($files as $file) {
            if ($file->isDir === null) {
                continue;
            }

            $path = $directory . '/' . $file->name;

            if ($file->isDir) {
                $filesInDir = $this->doRecursivelyListFilesInDirectory($path);

                foreach ($filesInDir as $pathInDir => $fileInDir) {
                    $result[$pathInDir] = $fileInDir;
                }
            } else {
                $result[$path] = $file;
            }
        }

        return $result;
    }

    /**
     * Sends an arbitrary command to the FTP server.
     *
     * Returns the server's response as an array of strings. No parsing is performed on the response string.
     * Note that this method cannot determine if the command succeeded.
     *
     * @param string $command
     *
     * @return string[]
     *
     * @throws FtpException If not connected, or an unknown error occurs.
     */
    public function sendRawCommand(string $command) : array
    {
        $this->throwIfNotConnected();

        return $this->call(true, 'ftp_raw', $this->conn, $command);
    }

    /**
     * Renames a file or a directory on the FTP server.
     *
     * @param string $oldname The old file/directory name.
     * @param string $newname The new name.
     *
     * @return void
     *
     * @throws FtpException If not connected, or renaming fails.
     */
    public function rename(string $oldname, string $newname) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_rename', $this->conn, $oldname, $newname);

        if (! $success) {
            throw new FtpException("Unable to rename $oldname to $newname.");
        }
    }

    /**
     * Deletes a file on the FTP server.
     *
     * @param string $path The remote file path.
     *
     * @return void
     *
     * @throws FtpException If not connected, or deleting fails.
     */
    public function delete(string $path) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_delete', $this->conn, $path);

        if (! $success) {
            throw new FtpException("Unable to delete file $path.");
        }
    }

    /**
     * Removes a directory on the FTP server.
     *
     * @param string $path The remote file path.
     *
     * @return void
     *
     * @throws FtpException If not connected, or removal fails.
     */
    public function removeDirectory(string $path) : void
    {
        $this->throwIfNotConnected();

        $success = $this->call(true, 'ftp_rmdir', $this->conn, $path);

        if (! $success) {
            throw new FtpException("Unable to remove directory $path.");
        }
    }

    /**
     * Returns the size of the given file.
     *
     * @param string $path The remote file path.
     *
     * @return int The file size.
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function getSize(string $path) : int
    {
        $this->throwIfNotConnected();

        $size = $this->call(true, 'ftp_size', $this->conn, $path);

        if ($size === -1) {
            throw new FtpException("Unable to get size of file $path.");
        }

        return $size;
    }

    /**
     * Downloads a file from the FTP server.
     *
     * @param string|resource $localFile  The local file path, or an open file pointer in which we store the data.
     *                                    If a path is provided, and the file already exists, it will be overwritten.
     * @param string          $remoteFile The remote file path.
     * @param int             $mode       The transfer mode. Must be either FTP_ASCII or FTP_BINARY.
     * @param int             $resumePos  The position in the remote file to start downloading from.
     *
     * @return void
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function download($localFile, string $remoteFile, int $mode = FTP_BINARY, int $resumePos = 0) : void
    {
        if (! is_string($localFile) && ! is_resource($localFile)) {
            throw new TypeError(sprintf(
                'Argument 1 passed to download() must be of the type string|resource, %s given.',
                gettype($localFile)
            ));
        }

        $this->throwIfNotConnected();

        if (is_string($localFile)) {
            $success = $this->call(true, 'ftp_get', $this->conn, $localFile, $remoteFile, $mode, $resumePos);
        } else {
            $success = $this->call(true, 'ftp_fget', $this->conn, $localFile, $remoteFile, $mode, $resumePos);
        }

        if (! $success) {
            throw new FtpException("Unable to download file $remoteFile to $localFile.");
        }
    }

    /**
     * Uploads a file to the FTP server.
     *
     * @param string|resource $localFile  The local file path, or an open file pointer on the local file.
     * @param string          $remoteFile The remote file path.
     * @param int             $mode       The transfer mode. Must be either FTP_ASCII or FTP_BINARY.
     * @param int             $startPos   The position in the remote file to start uploading to.
     *
     * @return void
     *
     * @throws FtpException If not connected, or an error occurs.
     */
    public function upload($localFile, string $remoteFile, int $mode = FTP_BINARY, int $startPos = 0) : void
    {
        if (! is_string($localFile) && ! is_resource($localFile)) {
            throw new TypeError(sprintf(
                'Argument 1 passed to upload() must be of the type string|resource, %s given.',
                gettype($localFile)
            ));
        }

        $this->throwIfNotConnected();

        if (is_string($localFile)) {
            $success = $this->call(true, 'ftp_put', $this->conn, $remoteFile, $localFile, $mode, $startPos);
        } else {
            $success = $this->call(true, 'ftp_fput', $this->conn, $remoteFile, $localFile, $mode, $startPos);
        }

        if (! $success) {
            throw new FtpException("Unable to upload file $localFile to $remoteFile.");
        }
    }

    /**
     * @return void
     *
     * @throws FtpException If no connection is currently open.
     */
    private function throwIfNotConnected() : void
    {
        if (! $this->conn) {
            throw new FtpException('No FTP connection is currently open.');
        }
    }

    /**
     * Executes the given function, catching errors and throwing them as exceptions.
     *
     * @param bool     $throw         Whether or not to throw an exception (true) or ignore (false) on error.
     * @param callable $function      The native ftp_* function.
     * @param mixed    ...$parameters The function parameters.
     *
     * @return mixed The function return value.
     *
     * @throws FtpException
     */
    private function call(bool $throw, callable $function, ...$parameters)
    {
        if ($throw) {
            set_error_handler([$this, 'errorHandlerException']);
        } else {
            set_error_handler([$this, 'errorHandlerIgnore']);
        }

        try {
            return call_user_func_array($function, $parameters);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param int    $code
     * @param string $message
     *
     * @return void
     *
     * @throws FtpException
     */
    private function errorHandlerException(int $code, string $message) : void
    {
        if (ini_get('html_errors')) {
            $message = html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $pos = strpos($message, ': ');

        if ($pos !== false) {
            $message = substr($message, $pos + 2);
        }

        throw new FtpException($message);
    }

    /**
     * @return void
     */
    private function errorHandlerIgnore() : void
    {
        // Intentionally empty.
    }
}
