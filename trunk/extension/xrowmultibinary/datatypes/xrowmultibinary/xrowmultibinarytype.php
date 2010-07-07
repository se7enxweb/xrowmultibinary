<?php


class xrowMultiBinaryType extends eZDataType
{
    const MAX_FILESIZE_FIELD = 'data_int1';

    const MAX_FILESIZE_VARIABLE = '_ezbinaryfile_max_filesize_';

    const DATA_TYPE_STRING = "xrowmultibinary";

    function __construct()
    {
        $this->eZDataType( self::DATA_TYPE_STRING, ezi18n( 'kernel/classes/datatypes', 'Multiple Files', 'Datatype name' ),
                           array( 'serialize_supported' => true ) );
    }

    /*!
     \return the binary file handler.
    */
    function fileHandler()
    {
        return eZBinaryFileHandler::instance();
    }

    /*!
     Sets value according to current version
    */
    function initializeObjectAttribute( $contentObjectAttribute, $currentVersion, $originalContentObjectAttribute )
    {
        if ( $currentVersion != false )
        {
            $contentObjectAttributeID = $originalContentObjectAttribute->attribute( 'id' );
            $version = $contentObjectAttribute->attribute( 'version' );
            $oldfiles = eZBinaryFile2::fetch( $contentObjectAttributeID, $currentVersion );
            foreach ( $oldfiles as $oldfile ){

                $oldfile->setAttribute( 'contentobject_attribute_id', $contentObjectAttribute->attribute( 'id' ) );
                $oldfile->setAttribute( 'version',  $version );
                $oldfile->store();
            }
        }
    }
/*
 * @TODO
 */
    /*!
     The object is being moved to trash, do any necessary changes to the attribute.
     Rename file and update db row with new name, so that access to the file using old links no longer works.
    */
    function trashStoredObjectAttribute( $contentObjectAttribute, $version = null )
    {
        $contentObjectAttributeID = $contentObjectAttribute->attribute( "id" );
        $sys = eZSys::instance();
        $storage_dir = $sys->storageDirectory();

        if ( $version == null )
            $binaryFiles = eZBinaryFile::fetch( $contentObjectAttributeID );
        else
            $binaryFiles = array( eZBinaryFile::fetch( $contentObjectAttributeID, $version ) );

        foreach ( $binaryFiles as $binaryFile )
        {
            if ( $binaryFile == null )
                continue;
            $mimeType =  $binaryFile->attribute( "mime_type" );
            list( $prefix, $suffix ) = split ('[/]', $mimeType );
            $orig_dir = $storage_dir . '/original/' . $prefix;
            $fileName = $binaryFile->attribute( "filename" );

            // Check if there are any other records in ezbinaryfile that point to that fileName.
            $binaryObjectsWithSameFileName = eZBinaryFile::fetchByFileName( $fileName );

            $filePath = $orig_dir . "/" . $fileName;
            $file = eZClusterFileHandler::instance( $filePath );

            if ( $file->exists() and count( $binaryObjectsWithSameFileName ) <= 1 )
            {
                // create dest filename in the same manner as eZHTTPFile::store()
                // grab file's suffix
                $fileSuffix = eZFile::suffix( $fileName );
                // prepend dot
                if ( $fileSuffix )
                    $fileSuffix = '.' . $fileSuffix;
                // grab filename without suffix
                $fileBaseName = basename( $fileName, $fileSuffix );
                // create dest filename
                $newFileName = md5( $fileBaseName . microtime() . mt_rand() ) . $fileSuffix;
                $newFilePath = $orig_dir . "/" . $newFileName;

                // rename the file, and update the database data
                $file->move( $newFilePath );
                $binaryFile->setAttribute( 'filename', $newFileName );
                $binaryFile->store();
            }
        }
    }

    function deleteStoredObjectAttribute( $contentObjectAttribute, $version = null )
    {
        $contentObjectAttributeID = $contentObjectAttribute->attribute( "id" );
        $sys = eZSys::instance();
        $storage_dir = $sys->storageDirectory();

        if ( $version == null )
        {
            $binaryFiles = eZBinaryFile::fetch( $contentObjectAttributeID );
            eZBinaryFile::removeByID( $contentObjectAttributeID, null );

            foreach ( $binaryFiles as  $binaryFile )
            {
                $mimeType =  $binaryFile->attribute( "mime_type" );
                list( $prefix, $suffix ) = explode('/', $mimeType );
                $orig_dir = $storage_dir . '/original/' . $prefix;
                $fileName = $binaryFile->attribute( "filename" );

                // Check if there are any other records in ezbinaryfile that point to that fileName.
                $binaryObjectsWithSameFileName = eZBinaryFile::fetchByFileName( $fileName );

                $filePath = $orig_dir . "/" . $fileName;
                $file = eZClusterFileHandler::instance( $filePath );

                if ( $file->exists() and count( $binaryObjectsWithSameFileName ) < 1 )
                    $file->delete();
            }
        }
        else
        {
            $count = 0;
            $binaryFile = eZBinaryFile::fetch( $contentObjectAttributeID, $version );
            if ( $binaryFile != null )
            {
                $mimeType =  $binaryFile->attribute( "mime_type" );
                list( $prefix, $suffix ) = explode('/', $mimeType );
                $orig_dir = $storage_dir . "/original/" . $prefix;
                $fileName = $binaryFile->attribute( "filename" );

                eZBinaryFile::removeByID( $contentObjectAttributeID, $version );

                // Check if there are any other records in ezbinaryfile that point to that fileName.
                $binaryObjectsWithSameFileName = eZBinaryFile::fetchByFileName( $fileName );

                $filePath = $orig_dir . "/" . $fileName;
                $file = eZClusterFileHandler::instance( $filePath );

                if ( $file->exists() and count( $binaryObjectsWithSameFileName ) < 1 )
                    $file->delete();
            }
        }
    }

    function validateObjectAttributeHTTPInput( $http, $base, $contentObjectAttribute )
    {
        $classAttribute = $contentObjectAttribute->contentClassAttribute();
        $maxSize = 1024 * 1024 * $classAttribute->attribute( self::MAX_FILESIZE_FIELD );

        if ( $contentObjectAttribute->validateIsRequired() )
        {
            $contentObjectAttributeID = $contentObjectAttribute->attribute( "id" );
            $version = $contentObjectAttribute->attribute( "version" );
            $binaryArray = eZBinaryFile2::fetch( $contentObjectAttributeID, $version );
            if ( count( $binaryArray ) === 0 )
            {
                $contentObjectAttribute->setValidationError( ezi18n( 'kernel/classes/datatypes',
                                                                 'A valid file is required.' ) );
            return eZInputValidator::STATE_INVALID;
            }
        }

        return eZInputValidator::STATE_ACCEPTED;
    }

    /*
     * Get the new file array after maybe deleting and check with the existing db table data
     */
    function fetchObjectAttributeHTTPInput( $http, $base, $contentObjectAttribute )
    {
        if ( $http->hasPostVariable( 'plup_tmp_name' ) )
        {
            $contentObjectAttributeID = $contentObjectAttribute->attribute( "id" );
            $version = $contentObjectAttribute->attribute( "version" );
            $binaryArray = eZBinaryFile2::fetch( $contentObjectAttributeID, $version );

            if ( is_array( $binaryArray ) && count( $binaryArray ) > 0 )
            {
                $files = $http->postVariable( 'plup_tmp_name' );

                foreach ( $binaryArray as $binaryFile )
                {
                    if ( !in_array( $binaryFile->attribute( 'original_filename' ), $files ) )
                    {
                        eZBinaryFile2::removeByFileName( $binaryFile->attribute( 'filename' ), $binaryFile->attribute( 'contentobject_attribute_id' ), $binaryFile->attribute( 'version' ) );
                    }
                }
                $contentObjectAttribute->setAttribute( 'data_text', serialize( $files ) );
                $contentObjectAttribute->store();
            }
        }
    }

    /*!
     Does nothing, since the file has been stored. See fetchObjectAttributeHTTPInput for the actual storing.
    */
    function storeObjectAttribute( $contentObjectAttribute )
    {
    }

    function customObjectAttributeHTTPAction( $http, $action, $contentObjectAttribute, $parameters )
    {
        if( $action == 'delete_binary' )
        {
            $contentObjectAttributeID = $contentObjectAttribute->attribute( "id" );
            $version = $contentObjectAttribute->attribute( "version" );
            $this->deleteStoredObjectAttribute( $contentObjectAttribute, $version );
        }
    }

    function isHTTPFileInsertionSupported()
    {
        return false;
    }

    function isRegularFileInsertionSupported()
    {
        return true;
    }


    function insertRegularFile( $object, $objectVersion, $objectLanguage,
                                $objectAttribute, $filePath,
                                &$result )
    {
        throw new Exception( 'Method "'.__METHOD__.'" not supported' );
    }


    function hasStoredFileInformation( $object, $objectVersion, $objectLanguage,
                                       $objectAttribute )
    {
        return false;
    }

    static function storedFileInformation2( $objectAttribute, $id )
    {
    	$binaryFile = eZPersistentObject::fetchObject( eZBinaryFile::definition(),
                                                    null,
                                                    array( 'contentobject_attribute_id' => $objectAttribute->attribute( 'id' ),
                                                           'version' => $objectAttribute->attribute( 'version' ),
                                                           'filename' => $id)
                                                      );

        if ( $binaryFile )
        {
            return $binaryFile->storedFileInfo();
        }
        return false;
    }

    static function handleDownload2( $objectAttribute,$id )
    {
        $binaryFile = eZPersistentObject::fetchObject( eZBinaryFile::definition(),
                                                    null,
                                                    array( 'contentobject_attribute_id' => $objectAttribute->attribute( 'id' ),
                                                           'version' => $objectAttribute->attribute( 'version' ),
                                                           'filename' => $id)
                                                      );

        $contentObjectAttributeID = $objectAttribute->attribute( 'id' );
        $version =  $objectAttribute->attribute( 'version' );

        if ( $binaryFile )
        {
            $db = eZDB::instance();
            $db->query( 'UPDATE ezbinaryfile 
                         SET download_count = ( download_count+1 )
                         WHERE contentobject_attribute_id = $contentObjectAttributeID 
                         AND version= $version 
                         AND filename= "'.eZDB::instance()->escapeString( $id ).'"' );
            return true;
        }
        return false;
    }

    function fetchClassAttributeHTTPInput( $http, $base, $classAttribute )
    {
        $filesizeName = $base . self::MAX_FILESIZE_VARIABLE . $classAttribute->attribute( 'id' );
        if ( $http->hasPostVariable( $filesizeName ) )
        {
            $filesizeValue = $http->postVariable( $filesizeName );
            $classAttribute->setAttribute( self::MAX_FILESIZE_FIELD, $filesizeValue );
        }
    }

    function title( $contentObjectAttribute,  $name = 'original_filename' )
    {
        $value = false;
    	$binaryFiles = eZPersistentObject::fetchObjectList( eZBinaryFile::definition(),
                                                        null,
                                                        array( 'contentobject_attribute_id' => $contentObjectAttribute->attribute( 'id' ),
                                                           'version' => $contentObjectAttribute->attribute( 'version' ) ) );
        $names = array();
        foreach( $binaryFiles as $binaryFile){
        if ( is_object( $binaryFile ) )
            $names[] = $binaryFile->attribute( $name );
        }
        return join( ', ', $names );
    }

    function hasObjectAttributeContent( $contentObjectAttribute )
    {
    	$binaryFiles = eZPersistentObject::fetchObjectList( eZBinaryFile::definition(),
                                                        null,
                                                        array( 'contentobject_attribute_id' => $contentObjectAttribute->attribute( 'id' ),
                                                           'version' => $contentObjectAttribute->attribute( 'version' ) ) );

        if ( count( $binaryFiles ) > 0 )
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    function objectAttributeContent( $contentObjectAttribute )
    {
        $binaryFiles = eZPersistentObject::fetchObjectList( eZBinaryFile::definition(),
                                                        null,
                                                        array( 'contentobject_attribute_id' => $contentObjectAttribute->attribute( 'id' ),
                                                           'version' => $contentObjectAttribute->attribute( 'version' ) ) );

        if ( count( $binaryFiles ) == 0 )
        {
            $attrValue = false;
            return $attrValue;
        }

        // sort the files
        $sortconditions = unserialize( $contentObjectAttribute->attribute( 'data_text' ) );
        if ( is_array( $sortconditions ) && count( $sortconditions ) > 0 )
        {
            foreach ( $binaryFiles as $binary )
            {
                // don't use array_search because array_search didn't read value with the key 0. don't know why...
                foreach ( $sortconditions as $key => $value )
                {
                    if ( $binary->attribute( 'original_filename' ) == $value )
                    {
                        $sortedBinaryFiles[$key] = $binary;
                    }
                }
            }
        }
        return $sortedBinaryFiles;
    }

    function isIndexable()
    {
        return true;
    }

    function metaData( $contentObjectAttribute )
    {
        $binaryFiles = $contentObjectAttribute->content();

        $metaData = '';
        foreach( $binaryFiles as $file )
        {
        if ( $file instanceof eZBinaryFile )
        {
            $metaData .= $file->metaData();
        }
        }
        return $metaData;
    }

    function serializeContentClassAttribute( $classAttribute, $attributeNode, $attributeParametersNode )
    {
        $dom = $attributeParametersNode->ownerDocument;
        $maxSize = $classAttribute->attribute( self::MAX_FILESIZE_FIELD );
        $maxSizeNode = $dom->createElement( 'max-size' );
        $maxSizeNode->appendChild( $dom->createTextNode( $maxSize ) );
        $maxSizeNode->setAttribute( 'unit-size', 'mega' );
        $attributeParametersNode->appendChild( $maxSizeNode );
    }

    function unserializeContentClassAttribute( $classAttribute, $attributeNode, $attributeParametersNode )
    {
        $sizeNode = $attributeParametersNode->getElementsByTagName( 'max-size' )->item( 0 );
        $maxSize = $sizeNode->textContent;
        $unitSize = $sizeNode->getAttribute( 'unit-size' );
        $classAttribute->setAttribute( self::MAX_FILESIZE_FIELD, $maxSize );
    }

    /*
     * @TODO: hier die Funktion anpassen, dass mehrere Dateien eingelesen werden können. Momentan ist es nur für eine Datei gedacht
     */
    function serializeContentObjectAttribute( $package, $objectAttribute )
    {
        $node = $this->createContentObjectAttributeDOMNode( $objectAttribute );

        $binaryFile = $objectAttribute->attribute( 'content' );
        if ( is_object( $binaryFile ) )
        {
            $fileKey = md5( mt_rand() );
            $package->appendSimpleFile( $fileKey, $binaryFile->attribute( 'filepath' ) );

            $dom = $node->ownerDocument;
            $fileNode = $dom->createElement( 'binary-file' );
            $fileNode->setAttribute( 'filesize', $binaryFile->attribute( 'filesize' ) );
            $fileNode->setAttribute( 'filename', $binaryFile->attribute( 'filename' ) );
            $fileNode->setAttribute( 'original-filename', $binaryFile->attribute( 'original_filename' ) );
            $fileNode->setAttribute( 'mime-type', $binaryFile->attribute( 'mime_type' ) );
            $fileNode->setAttribute( 'filekey', $fileKey );
            $node->appendChild( $fileNode );
        }

        return $node;
    }

    /*
     * @TODO: hier die Funktion anpassen, dass mehrere Dateien eingelesen werden können. Momentan ist es nur für eine Datei gedacht
     */
    function unserializeContentObjectAttribute( $package, $objectAttribute, $attributeNode )
    {
        $fileNode = $attributeNode->getElementsByTagName( 'binary-file' )->item( 0 );
        if ( !is_object( $fileNode ) or !$fileNode->hasAttributes() )
        {
            return;
        }

        $binaryFile = eZBinaryFile::create( $objectAttribute->attribute( 'id' ), $objectAttribute->attribute( 'version' ) );

        $sourcePath = $package->simpleFilePath( $fileNode->getAttribute( 'filekey' ) );

        if ( !file_exists( $sourcePath ) )
        {
            eZDebug::writeError( 'The file "$sourcePath" does not exist, cannot initialize file attribute with it',
                                 'eZBinaryFileType::unserializeContentObjectAttribute' );
            return false;
        }

        $ini = eZINI::instance();
        $mimeType = $fileNode->getAttribute( 'mime-type' );
        list( $mimeTypeCategory, $mimeTypeName ) = explode( '/', $mimeType );
        $destinationPath = eZSys::storageDirectory() . '/original/' . $mimeTypeCategory . '/';
        if ( !file_exists( $destinationPath ) )
        {
            $oldumask = umask( 0 );
            if ( !eZDir::mkdir( $destinationPath, false, true ) )
            {
                umask( $oldumask );
                return false;
            }
            umask( $oldumask );
        }

        $basename = basename( $fileNode->getAttribute( 'filename' ) );
        while ( file_exists( $destinationPath . $basename ) )
        {
            $basename = substr( md5( mt_rand() ), 0, 8 ) . '.' . eZFile::suffix( $fileNode->getAttribute( 'filename' ) );
        }

        eZFileHandler::copy( $sourcePath, $destinationPath . $basename );
        eZDebug::writeNotice( 'Copied: ' . $sourcePath . ' to: ' . $destinationPath . $basename,
                              'eZBinaryFileType::unserializeContentObjectAttribute()' );

        $binaryFile->setAttribute( 'contentobject_attribute_id', $objectAttribute->attribute( 'id' ) );
        $binaryFile->setAttribute( 'filename', $basename );
        $binaryFile->setAttribute( 'original_filename', $fileNode->getAttribute( 'original-filename' ) );
        $binaryFile->setAttribute( 'mime_type', $fileNode->getAttribute( 'mime-type' ) );

        $binaryFile->store();

        $fileHandler = eZClusterFileHandler::instance();
        $fileHandler->fileStore( $destinationPath . $basename, 'binaryfile', true );
    }

    function supportsBatchInitializeObjectAttribute()
    {
        return true;
    }
}

eZDataType::register( xrowMultiBinaryType::DATA_TYPE_STRING, 'xrowMultiBinaryType' );

?>