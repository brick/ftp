# Brick\Ftp

<img src="https://raw.githubusercontent.com/brick/brick/master/logo.png" alt="" align="left" height="64">

An object-oriented FTP client for PHP.

[![Latest Stable Version](https://poser.pugx.org/brick/ftp/v/stable)](https://packagist.org/packages/brick/ftp)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](http://opensource.org/licenses/MIT)

## Installation

This library is installable via [Composer](https://getcomposer.org/):

```bash
composer require brick/ftp
```

## Requirements

This library requires PHP 7.2 or later, and the [ftp](https://www.php.net/manual/en/book.ftp.php) extension.

## Project status & release process

This library is abandoned and will not receive further development.

## Package contents

This repo has only 3 classes, in the `Brick\Ftp` namespace:

- `FtpClient` is the main class to interact with an FTP server
- `FtpException` is thrown if any operation fails
- `FtpFileInfo` is returned when listing directories

## Quickstart

### Connect & log in

```php
use Brick\Ftp\FtpClient;
use Brick\Ftp\FtpException;

try {
    $client = new FtpClient();

    $host    = 'ftp.example.com'; // FTP server host name
    $port    = 21;                // FTP server port
    $ssl     = false;             // whether to open a secure SSL connection
    $timeout = 90;                // timeout in seconds

    $username = 'ftp-user';
    $password = 'p@ssw0rd';

    $client->connect($host, $port, $ssl, $timeout);
    $client->login($username, $password);

    // You usually want to set passive mode (PASV) on; see below for an explanation
    $client->setPassive(true);
} catch (FtpException $e) {
    // An error occurred!
}
```

**Only the host name is required** in `connect()`, the other values (port, SSL, timeout) are optional and default to the values above.

#### What's passive mode?

Passive mode, known as the `PASV` FTP command, is a way to tell the server to open ports where the client can connect to, to upload/download a file.

By default (passive mode not enabled), the client would open a local port and request the server to connect back to the client instead.

This requires that the ports in question are not blocked by a firewall, and are directly open to the internet (no NAT, or using port forwarding). In practice, it's much easier to use passive mode as most FTP servers are already configured to support it.

#### Exception handling

As you've seen above, we wrap all calls to `FtpClient` methods in a try-catch block. We won't do this in subsequent examples below for conciseness, but you should catch `FtpException` in *every* call to `FtpClient` methods, or you application will exit with "Uncaught Exception" if any error occurs.

### Get the working directory

```php
echo $client->getWorkingDirectory(); // e.g. /home/ftp-user
```

### Set the working directory

```php
$client->setWorkingDirectory('/home/ftp-user/archive');
```

### List a directory

```php
$files = $client->listDirectory('.');

foreach ($files as $file) {
    // $file is an FtpFileInfo object
    echo $file->name, PHP_EOL;
}
```

Each value in the array returned by `listDirectory()` is an `FtpFileInfo` object. Depending on the capabilities of the FTP server, it may contain as little as only the file name, or additional information:

| Property                | Type          | Nullable (optional)   | Description                                |
| ----------------------- | ------------- | --------------------- | ------------------------------------------ |
| `$name`                 | `string`      | No                    | The file name                              |
| `$isDir`                | `bool`        | *Yes*                 | `true` for a directory, `false` for a file |
| `$size`                 | `int`         | *Yes*                 | The file size, in bytes                    |
| `$creationTime`         | `string`      | *Yes*                 | The creation time                          |
| `$lastModificationTime` | `string`      | *Yes*                 | The last modification time                 |
| `$uniqueId`             | `string`      | *Yes*                 | A unique identifier for the file           |

If the server does not support the `MLSD` command, only the file name will be available.
If the server does support this command, additional information will be available; which ones depends on the server.
As a result, you should check if a property is `null` before attempting to use it, and act accordingly.

Creation time and last modification time, if available, will be in either of these formats:

- `YYYYMMDDHHMMSS`
- `YYYYMMDDHHMMSS.sss`

### Recursively list all files under a given directory

This will traverse the given directory and all of its subdirectories, and return all files found.

Just like `listDirectory()`, the result is an array of `FtpFileInfo` objects, but the keys of the array are the path to the file, *relative to the given directory*.

```php
$files = $client->recursivelyListFilesInDirectory('.');

foreach ($files as $path => $file) {
    echo $path, PHP_EOL; // a/b/c.txt
    echo $file->name, PHP_EOL; // c.txt
}
```

Please note that this depends on the ability for the client to differentiate between files and directories. As a result, if the server does not support the `MLSD` command, the result will always be an empty array.

Also, please be aware that depending on the number of files and directories, this method may take a long time to execute.

### Rename a file or a directory

```php
$client->rename('old/path/to/file', 'new/path/to/file');
```

### Delete a file

```php
$client->delete('path/to/file');
```

### Remove a directory

```php
$client->removeDirectory('path/to/directory');
```

The directory must be empty, or an exception is thrown.

### Get the size of a file

```php
$size = $client->getSize('path/to/file'); // e.g. 123456
```

### Download a file

```php
$client->download($localFile, $remoteFile);
```

- `$localFile` can be either a `string` containing the local file name, or a `resource` containing a file pointer
- `$remoteFile` is the path of the file on the FTP server

This method accepts 2 additional, optional parameters:

- `$mode`: `FTP_BINARY` (default) or `FTP_ASCII` (see below for an explanation)
- `$resumePos`: the position in the remote file to start downloading from (default `0`)

#### `FTP_BINARY` or `FTP_ASCII`?

- `FTP_BINARY` transfers the file as is, without any modification, and is the default value.
- `FTP_ASCII` converts newlines in the file (assuming it's a text file) to the format expected by the target platform. You should usually not use this mode.

### Upload a file

```php
$client->upload($localFile, $remoteFile);
```

- `$localFile` can be either a `string` containing the local file name, or a `resource` containing a file pointer
- `$remoteFile` is the destination path of the file on the FTP server

This method accepts 2 additional, optional parameters:

- `$mode`: `FTP_BINARY` (default) or `FTP_ASCII` (see above for an explanation)
- `$startPos`: the position in the remote file to start uploading to (default `0`)

### Send a raw command

If for any reason, you need to send a raw FTP command to the server, this method is for you.
The result is an array of all lines in the response returned by the server.

Note that this method does not check if the server response contains an error code, it always returns the raw output.

```php
$lines = $client->sendRawCommand('FEAT');

foreach ($lines as $line) {
    echo $line, PHP_EOL;
}
```

Sample response:

```
211- Extensions supported:
 AUTH TLS
 PBSZ
 PROT
 CCC
 SIZE
 MDTM
 REST STREAM
 MFMT
 TVFS
 MLST
 MLSD
 UTF8
211 End.
```

### Close the connection

```php
$client->close();
```
