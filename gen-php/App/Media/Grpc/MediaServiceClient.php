<?php
// GENERATED CODE -- DO NOT EDIT!

namespace App\Media\Grpc;

/**
 * MediaService is the CONTROL-PLANE API that Symfony uses to manage blobs
 * (upload, move, delete, inspect). Byte downloads to the browser do NOT go
 * through here — those use short-lived signed HTTP URLs served directly by the
 * Go service, so PHP never streams file bytes.
 */
class MediaServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Store uploads a blob as a stream of chunks (client streaming). The FIRST
     * message MUST carry `key`; every message (including the first) may carry a
     * `chunk`. Streaming keeps arbitrarily large files off the heap.
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ClientStreamingCall
     */
    public function Store($metadata = [], $options = []) {
        return $this->_clientStreamRequest('/media.v1.MediaService/Store',
        ['\App\Media\Grpc\StoreResponse','decode'],
        $metadata, $options);
    }

    /**
     * Read streams a blob's bytes back (server streaming), for the server-side
     * FileStorage.readStream. Browser-facing downloads use signed HTTP URLs
     * instead, so this path is for internal use (reconcile, re-processing).
     * @param \App\Media\Grpc\ReadRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function Read(\App\Media\Grpc\ReadRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/media.v1.MediaService/Read',
        $argument,
        ['\App\Media\Grpc\ReadResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Stat returns metadata without reading the bytes.
     * @param \App\Media\Grpc\StatRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\StatResponse>
     */
    public function Stat(\App\Media\Grpc\StatRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/Stat',
        $argument,
        ['\App\Media\Grpc\StatResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Delete removes one blob (idempotent).
     * @param \App\Media\Grpc\DeleteRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\DeleteResponse>
     */
    public function Delete(\App\Media\Grpc\DeleteRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/Delete',
        $argument,
        ['\App\Media\Grpc\DeleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Move relocates a blob, e.g. staging key -> final key.
     * @param \App\Media\Grpc\MoveRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\MoveResponse>
     */
    public function Move(\App\Media\Grpc\MoveRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/Move',
        $argument,
        ['\App\Media\Grpc\MoveResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * DeleteDirectory removes every blob under a prefix (idempotent).
     * @param \App\Media\Grpc\DeleteDirectoryRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\DeleteDirectoryResponse>
     */
    public function DeleteDirectory(\App\Media\Grpc\DeleteDirectoryRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/DeleteDirectory',
        $argument,
        ['\App\Media\Grpc\DeleteDirectoryResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Directories lists immediate child directory names under a prefix.
     * @param \App\Media\Grpc\DirectoriesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\DirectoriesResponse>
     */
    public function Directories(\App\Media\Grpc\DirectoriesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/Directories',
        $argument,
        ['\App\Media\Grpc\DirectoriesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * GenerateThumbnails reads the source blob and writes resized WebP (or other
     * format) variants next to it. The service has NO domain knowledge ("cover",
     * "avatar", …): each spec carries the target aspect ratio and one side length,
     * and the service derives the rest. Deterministic keys, so re-running overwrites.
     * @param \App\Media\Grpc\GenerateThumbnailsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\App\Media\Grpc\GenerateThumbnailsResponse>
     */
    public function GenerateThumbnails(\App\Media\Grpc\GenerateThumbnailsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/media.v1.MediaService/GenerateThumbnails',
        $argument,
        ['\App\Media\Grpc\GenerateThumbnailsResponse', 'decode'],
        $metadata, $options);
    }

}
