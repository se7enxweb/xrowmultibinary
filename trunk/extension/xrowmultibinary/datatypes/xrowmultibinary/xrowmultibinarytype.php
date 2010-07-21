<?php


class xrowMultiBinaryType extends eZDataType
{
    const MAX_FILESIZE_FIELD = 'data_int1';
    const MAX_NUMBER_OF_FILES_FIELD = 'data_int2';

    const MAX_FILESIZE_VARIABLE = '_xrowmultibinary_max_filesize_';
    const MAX_NUMBER_OF_FILES_VARIABLE = '_xrowmultibinary_max_number_of_files_';

    const DATA_TYPE_STRING = 'xrowmultibinary';

    function __construct()
    {
        $this->eZDataType( self::DATA_TYPE_STRING, 
                           ezi18n( 'kernel/classes/datatypes', 'Multiple Files', 'Datatype name' ),
                           array( 'serialize_supported' => true ) );
    }

    /*!
     \return the binary file handler.
    */
    function fileHandler()
    {
        return eZBinaryFileHandler::instance();
    }

    function getBinaryFiles( $contentObjectAttribute )
    {
        $binaryFiles = array();
        $contentObjectAttributeID = $contentObjectAttribute->attribute( 'id' );
        $version = $contentObjectAttribute->attribute( 'version' );
        if ( $version == null )
        {
            $binaryFiles = eZBinaryFile2::fetch( $contentObjectAttributeID );
        }
        else
        {
            $binaryFiles = eZBinaryFile2::fetch( $contentObjectAttributeID, $version );
        }

        return $binaryFiles;
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
            foreach ( $oldfiles as $oldfile )
            {
                $oldfile->setAttribute( 'contentobject_attribute_id', $contentObjectAttribute->attribute( 'id' ) );
                $oldfile->setAttribute( 'version',  $version );
                $oldfile->store();
            }
        }
    }

    /*!
     The object is being moved to trash, do any necessary changes to the attribute.
     Rename file and update db row with new name, so that access to the file using old links no longer works.
    */
    function trashStoredObjectAttribute( $contentObjectAttribute, $version = null )
    {
        $sys = eZSys::instance();
        $storage_dir = $sys->storageDirectory();

        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

        if ( is_array( $binaryFiles ) && count( $binaryFiles ) > 0 )
        {
            foreach ( $binaryFiles as $binaryFile )
            {
                if ( $binaryFile instanceof eZBinaryFile2 )
                {
                    $mimeType =  $binaryFile->attribute( "mime_type" );
                    list( $prefix, $suffix ) = split ('[/]', $mimeType );
                    $orig_dir = $storage_dir . '/original/' . $prefix;
                    $fileName = $binaryFile->attribute( "filename" );

                    // Check if there are any other records in ezbinaryfile that point to that fileName.
                    $binaryObjectsWithSameFileName = eZBinaryFile2::fetchByFileName( $fileName );

                    $filePath = $orig_dir . "/" . $fileName;
                    $file = eZClusterFileHandler::instance( $filePath );

                    if ( $file->exists() and count( $binaryObjectsWithSameFileName ) <= 1 )
                    {
                        // create dest filename in the same manner as eZHTTPFile::store()
                        // grab file's suffix
                        $fileSuffix = eZFile::suffix( $fileName );
                        // prepend dot
                        if ( $fileSuffix )
                        {
                            $fileSuffix = '.' . $fileSuffix;
                        }
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
        }
    }

    function deleteStoredObjectAttribute( $contentObjectAttribute, $version = null )
    {
        $sys = eZSys::instance();
        $storage_dir = $sys->storageDirectory();
        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

        if ( $version == null )
        {
            eZBinaryFile2::removeByID( $contentObjectAttribute->attribute( 'id' ), null );
        }

        foreach ( $binaryFiles as $binaryFile )
        {
            if ( $binaryFile instanceof eZBinaryFile2 )
            {
                $mimeType =  $binaryFile->attribute( "mime_type" );
                list( $prefix, $suffix ) = explode('/', $mimeType );
                $orig_dir = $storage_dir . '/original/' . $prefix;
                $fileName = $binaryFile->attribute( "filename" );

                // Check if there are any other records in ezbinaryfile that point to that fileName.
                $binaryObjectsWithSameFileName = eZBinaryFile2::fetchByFileName( $fileName );

                $filePath = $orig_dir . "/" . $fileName;
                $file = eZClusterFileHandler::instance( $filePath );

                if ( $file->exists() and count( $binaryObjectsWithSameFileName ) < 1 )
                {
                    $file->delete();
                }
            }
        }
    }

    function validateObjectAttributeHTTPInput( $http, $base, $contentObjectAttribute )
    {
        $classAttribute = $contentObjectAttribute->contentClassAttribute();
        $maxSize = 1024 * 1024 * $classAttribute->attribute( self::MAX_FILESIZE_FIELD );
        $maxNumberOfFiles = $classAttribute->attribute( self::MAX_NUMBER_OF_FILES_FIELD );

        if ( $contentObjectAttribute->validateIsRequired() )
        {
            $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );
            if ( !is_array( $binaryFiles ) || count( $binaryFiles ) == 0 )
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
            $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

            if ( is_array( $binaryFiles ) && count( $binaryFiles ) > 0 )
            {
                $files = $http->postVariable( 'plup_tmp_name' );

                foreach ( $binaryFiles as $binaryFile )
                {
                    if ( $binaryFile instanceof eZBinaryFile2 )
                    {
                        if ( !in_array( $binaryFile->attribute( 'original_filename' ), $files ) )
                        {
                            eZBinaryFile2::removeByFileName( $binaryFile->attribute( 'filename' ), $binaryFile->attribute( 'contentobject_attribute_id' ), $binaryFile->attribute( 'version' ) );
                        }
                    }
                }
                // save the chronology of the files for sorting

            }
        }
        else
        {
        	$files = array();
        	$binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );
            foreach ( $binaryFiles as $binaryFile )
                {
                    if ( $binaryFile instanceof eZBinaryFile2 )
                    {
                            eZBinaryFile2::removeByFileName( $binaryFile->attribute( 'filename' ), $binaryFile->attribute( 'contentobject_attribute_id' ), $binaryFile->attribute( 'version' ) );

                    }
                }
        }
        $contentObjectAttribute->setAttribute( 'data_text', serialize( $files ) );
        $contentObjectAttribute->store();
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
            $contentObjectAttributeID = $contentObjectAttribute->attribute( 'id' );
            $version = $contentObjectAttribute->attribute( 'version' );
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
        $binaryFile = eZPersistentObject::fetchObject( eZBinaryFile2::definition(),
                                                        null,
                                                        array( 'contentobject_attribute_id' => $objectAttribute->attribute( 'id' ),
                                                               'version' => $objectAttribute->attribute( 'version' ),
                                                               'filename' => $id)
                                                      );

        if ( $binaryFile instanceof eZBinaryFile2 )
        {
            return $binaryFile->storedFileInfo();
        }
        return false;
    }

    static function handleDownload2( $objectAttribute, $id )
    {
        $binaryFile = eZPersistentObject::fetchObject( eZBinaryFile2::definition(),
                                                    null,
                                                    array( 'contentobject_attribute_id' => $objectAttribute->attribute( 'id' ),
                                                           'version' => $objectAttribute->attribute( 'version' ),
                                                           'filename' => $id)
                                                      );

        $contentObjectAttributeID = $objectAttribute->attribute( 'id' );
        $version =  $objectAttribute->attribute( 'version' );

        if ( $binaryFile instanceof eZBinaryFile2 )
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
        $filenumberName = $base . self::MAX_NUMBER_OF_FILES_VARIABLE . $classAttribute->attribute( 'id' );
        if ( $http->hasPostVariable( $filenumberName ) )
        {
            $filenumberValue = $http->postVariable( $filenumberName );
            $classAttribute->setAttribute( self::MAX_NUMBER_OF_FILES_FIELD, $filenumberValue );
        }
    }

    function title( $contentObjectAttribute, $name = 'original_filename' )
    {
        $names = array();
        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

        if ( is_array( $binaryFiles ) && count( $binaryFiles ) > 0 )
        {
            foreach( $binaryFiles as $binaryFile)
            {
                if ( $binaryFile instanceof eZBinaryFile2 )
                {
                    $names[] = $binaryFile->attribute( $name );
                }
            }
        }
        if ( count( $names ) > 0 )
        {
            return join( ', ', $names );
        }
        return array();
    }

    function hasObjectAttributeContent( $contentObjectAttribute )
    {
        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

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
        $contentObjectID = $contentObjectAttribute->attribute( 'contentobject_id' );
        $contentObject = eZContentObject::fetch( $contentObjectID );
        $data_map = $contentObject->dataMap();
        #$max_upload_count = $data_map['max_upload_count']->content();

        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );
        
        if ( !is_array( $binaryFiles ) || count( $binaryFiles ) == 0 )
        {
            $attrValue = false;
            return $attrValue;
        }

        // sort the files
        $sortconditions = unserialize( $contentObjectAttribute->attribute( 'data_text' ) );
        if ( is_array( $sortconditions ) && count( $sortconditions ) > 0 )
        {
            foreach ( $binaryFiles as $binaryFile )
            {
                if ( $binaryFile instanceof eZBinaryFile2 )
                {
                    // don't use array_search because array_search didn't read value with the key 0. don't know why...
                    foreach ( $sortconditions as $key => $value )
                    {
                        if ( $binaryFile->attribute( 'original_filename' ) == $value )
                        {
                            $sortedBinaryFiles[$key] = $binaryFile;
                        }
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
        $binaryFiles = $this->getBinaryFiles( $contentObjectAttribute );

        $metaData = '';
        foreach( $binaryFiles as $file )
        {
            if ( $file instanceof eZBinaryFile2 )
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
        $attributeParametersNode->appendChild( $maxSizeNode );

        $maxNumberOf = $classAttribute->attribute( self::MAX_NUMBER_OF_FILES_FIELD );
        $maxNumberOfNode = $dom->createElement( 'max-number-of' );
        $maxNumberOfNode->appendChild( $dom->createTextNode( $maxNumberOf ) );
        $attributeParametersNode->appendChild( $maxNumberOfNode );
    }

    function unserializeContentClassAttribute( $classAttribute, $attributeNode, $attributeParametersNode )
    {
        $maxSizeNode = $attributeParametersNode->getElementsByTagName( 'max-size' )->item( 0 );
        $maxSize = $maxSizeNode->textContent;
        $classAttribute->setAttribute( self::MAX_FILESIZE_FIELD, $maxSize );

        $maxNumberOfNode = $attributeParametersNode->getElementsByTagName( 'max-number-of' )->item( 0 );
        $maxNumberOf = $maxNumberOfNode->textContent;
        $classAttribute->setAttribute( self::MAX_NUMBER_OF_FILES_FIELD, $maxNumberOf );
    }

    function serializeContentObjectAttribute( $package, $objectAttribute )
    {
        $node = $this->createContentObjectAttributeDOMNode( $objectAttribute );
        $binaryFiles = $this->getBinaryFiles( $objectAttribute );
        // sort the files
        $sortconditions = unserialize( $objectAttribute->attribute( 'data_text' ) );
        if ( is_array( $sortconditions ) && count( $sortconditions ) > 0 )
        {
            foreach ( $binaryFiles as $binaryFile )
            {
                if ( $binaryFile instanceof eZBinaryFile2 )
                {
                    // don't use array_search because array_search didn't read value with the key 0. don't know why...
                    foreach ( $sortconditions as $key => $value )
                    {
                        if ( $binaryFile->attribute( 'original_filename' ) == $value )
                        {
                            $sortedBinaryFiles[$key] = $binaryFile;
                        }
                    }
                }
            }
        }
        
        if ( is_array( $sortedBinaryFiles ) && count( $sortedBinaryFiles ) > 0 )
        {
            foreach ( $sortedBinaryFiles as $key => $binaryFile)
            {
                $fileKey = md5( mt_rand() );
                $package->appendSimpleFile( $fileKey, $binaryFile->attribute( 'filepath' ) );

                $dom = $node->ownerDocument;
                $fileNode = $dom->createElement( 'multibinary-file' );
                $fileNode->setAttribute( 'filesize', $binaryFile->attribute( 'filesize' ) );
                $fileNode->setAttribute( 'sort-id', $key );
                $fileNode->setAttribute( 'filename', $binaryFile->attribute( 'filename' ) );
                $fileNode->setAttribute( 'original-filename', $binaryFile->attribute( 'original_filename' ) );
                $fileNode->setAttribute( 'mime-type', $binaryFile->attribute( 'mime_type' ) );
                $fileNode->setAttribute( 'filekey', $fileKey );
                $node->appendChild( $fileNode );
            }
        }

        return $node;
    }

    function unserializeContentObjectAttribute( $package, $objectAttribute, $attributeNode )
    {
        $fileNodes = $attributeNode->getElementsByTagName( 'multibinary-file' );
        foreach ( $fileNodes as $fileNode )
        {
            if ( !is_object( $fileNode ) or !$fileNode->hasAttributes() )
            {
                return;
            }
            $binaryFile = eZBinaryFile2::create( $objectAttribute->attribute( 'id' ), $objectAttribute->attribute( 'version' ) );
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
            
            $sort_array[$fileNode->getAttribute( 'sort-id' )] = $fileNode->getAttribute( 'original-filename' );

            $binaryFile->store();

            $fileHandler = eZClusterFileHandler::instance();
            $fileHandler->fileStore( $destinationPath . $basename, 'binaryfile', true );
        }
        // save the chronology of the files for sorting
        $objectAttribute->setAttribute( 'data_text', serialize( $sort_array ) );
        $objectAttribute->store();
    }

    function supportsBatchInitializeObjectAttribute()
    {
        return true;
    }
}

eZDataType::register( xrowMultiBinaryType::DATA_TYPE_STRING, 'xrowMultiBinaryType' );

?>