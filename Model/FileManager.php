<?php

namespace Kitpages\FileBundle\Model;

// external service
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\DoctrineBundle\Registry;

use Kitpages\FileBundle\Entity\File;
use Kitpages\FileBundle\Event\FileEvent;
use Kitpages\FileBundle\KitpagesFileEvents;

use Kitpages\UtilBundle\Service\Util;


class FileManager {
    ////
    // dependency injection
    ////
    protected $dispatcher = null;
    protected $doctrine = null;
    protected $logger = null;
    protected $util = null;
    protected $dataDir = null;
    protected $publicPrefix = null;
    protected $webRootDir = null;
    
    public function __construct(
        Registry $doctrine,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
        Util $util,
        $dataDir,
        $publicPrefix,
        $kernelRootDir
    )
    {
        $this->dispatcher = $dispatcher;
        $this->doctrine = $doctrine;
        $this->logger = $logger;
        $this->util = $util;
        $this->dataDir = $dataDir;
        $this->publicPrefix = $publicPrefix;
        $this->webRootDir = realpath($kernelRootDir.'/../web');
    }
    
    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @return EventDispatcherInterface $dispatcher
     */
    public function getDispatcher() {
        return $this->dispatcher;
    }
    
    /**
     * @return Registry
     */
    public function getDoctrine()
    {
        return $this->doctrine;
    }
    /**
     * @return Util
     */
    public function getUtil()
    {
        return $this->util;
    }

    ////
    // actions
    ////
    public function upload($tempFileName, $fileName) {
        $log = $this->getLogger();
        // send on event
        $event = new FileEvent();
        $event->set('tempFileName', $tempFileName);
        $event->set('fileName', $fileName);
        $event->set('dataDir', $this->dataDir);
        $file = new File();
        $file->setFileName($fileName);
        $file->setIsPrivate(false);
        $file->setIsPublished(false);
        $file->setData(array());
        $event->set('fileObject', $file);
        $this->getDispatcher()->dispatch(KitpagesFileEvents::onFileUpload, $event);
        // default action (upload)
        if (! $event->isDefaultPrevented()) {
            // manage object creation
            $file = $event->get('fileObject');
            $em = $this->getDoctrine()->getEntityManager();
            $em->persist($file);
            $this->getLogger()->info('file saved with id='.$file->getId());
            
            // manage upload
            $targetFileName = $this->getOriginalAbsoluteFileName($file);
            $originalDir = dirname($targetFileName);
            $this->getUtil()->mkdirr($originalDir);

            $log->info("start doUpload, $tempFileName => $fileName => $originalDir");
            if (move_uploaded_file($tempFileName,$targetFileName)) {
                $file->setHasUploadFailed(false);
            }
            else {
                $file->setHasUploadFailed(true);
            }
            $em->flush();
        }
        // send after event
        $this->getDispatcher()->dispatch(KitpagesFileEvents::afterFileUpload, $event);
        return $file;
    }
    
    public function publish(File $file)
    {
        $event = new FileEvent();
        $event->set('fileObject', $file);
        $this->getDispatcher()->dispatch(KitpagesFileEvents::onFilePublish, $event);
        if (!$event->isDefaultPrevented()) {
            $dir = $this->getFilePublicLocation($file);
            $targetDir = $this->webRootDir.'/'.$dir;
            if (is_dir($targetDir)) {
                $this->getUtil()->rmdirr($targetDir);
            }
            $this->getUtil()->mkdirr($targetDir);

            // copy original file
            copy($this->getOriginalAbsoluteFileName($file), $targetDir ) ;
            // copy generated files
            foreach (glob($this->getGenerationDir($file).'/*') as $fileName) {
                copy($fileName, $targetDir);
            }
        }
        $this->getDispatcher()->dispatch(KitpagesFileEvents::afterFilePublish, $event);
    }
    
    public function getOriginalAbsoluteFileName(File $file)
    {
        $idString = (string) $file->getId();
        if (strlen($idString)== 1) {
            $idString = '0'.$idString;
        }
        $dir = substr($idString, 0, 2);
        // manage upload
        $originalDir = $this->dataDir.'/original/'.$dir;
        $fileName = $originalDir.'/'.$file->getId().'-'.$file->getFilename();
        return $fileName;
    }
    
    public function getGenerationDir(File $file)
    {
        $idString = (string) $file->getId();
        if (strlen($idString)== 1) {
            $idString = '0'.$idString;
        }
        $dir = substr($idString, 0, 2);
        $generationDir = $this->dataDir.'/generated/'.$dir.'/'.$file->getId();
        $this->getUtil()->mkdirr($generationDir);
        return $generationDir;
    }
    
    
    public function getFilePublicLocation(File $file)
    {
        $idString = (string) $file->getId();
        if (strlen($idString)== 1) {
            $idString = '0'.$idString;
        }
        $dir = substr($idString, 0, 2);
        return $this->publicPrefix.'/'.$dir.'/'.$file->getId();
    }
}

?>