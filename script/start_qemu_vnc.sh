[jenkins@localhost euler]$ cat start_txt.sh
qemu-system-aarch64 \
  -m 64G \
  -cpu host \
  -smp 64 \
  -machine virt,accel=kvm,gic-version=3 \
  --enable-kvm \
  -bios QEMU_EFI.fd \
  -device virtio-gpu-pci \
  -device usb-ehci -device usb-kbd -device usb-mouse \
  -drive if=none,file=openEuler-vm.qcow2,format=qcow2,id=hd0 \
  -device virtio-blk-device,drive=hd0 \
  -cdrom openEuler-22.03-LTS-SP4-aarch64-dvd.iso \
  -netdev user,id=net0 -device virtio-net-device,netdev=net0 \
  -nographic \
  -serial mon:stdio
[jenkins@localhost euler]$ cat start_txt2.sh
#/home/jenkins/lilingfeng/qemu/build/qemu-system-aarch64 \
qemu-system-aarch64 \
  -m 64G \
  -cpu host \
  -smp 64 \
  -machine virt,accel=kvm,gic-version=3 \
  --enable-kvm \
  -bios QEMU_EFI.fd \
  -device virtio-gpu-pci,xres=1280,yres=720 \
  -device usb-ehci -device usb-kbd -device usb-mouse \
  -drive if=none,file=openEuler-vm.qcow2,format=qcow2,id=hd0 \
  -device virtio-blk-device,drive=hd0 \
  -cdrom openEuler-22.03-LTS-SP4-aarch64-dvd.iso \
  -net nic,model=virtio,macaddr=DE:AD:BE:EF:C8:C9 -net bridge,br=qemu_ci \
  -device virtio-scsi-pci \
  -drive file=mydisk_20G_2,if=none,format=raw,id=ds_1 \
  -device scsi-hd,drive=ds_1,id=disk_1 \
  -drive file=mydisk_20G_3,if=none,format=raw,id=ds_2 \
  -device scsi-hd,drive=ds_2,id=disk_2 \
  -drive file=mydisk_20G_4,if=none,format=raw,id=ds_3 \
  -device scsi-hd,drive=ds_3,id=disk_3 \
  -vnc :0\
  -serial stdio

#  -device vmware-svga,id=video0,xres=1280,yres=720
#  -device virtio-gpu-pci,xres=1280,yres=720 \
#  -nographic \
#  -serial mon:stdio

[jenkins@localhost euler]$ cat start.sh
qemu-system-aarch64 \
  -m 64G \
  -cpu host \
  -smp 64 \
  -machine virt,accel=kvm,gic-version=3 \
  --enable-kvm \
  -bios QEMU_EFI.fd \
  -device virtio-gpu-pci \
  -device usb-ehci -device usb-kbd -device usb-mouse \
  -drive if=none,file=openEuler-vm.qcow2,format=qcow2,id=hd0 \
  -device virtio-blk-device,drive=hd0 \
  -cdrom openEuler-22.03-LTS-SP4-aarch64-dvd.iso \
  -netdev user,id=net0 -device virtio-net-device,netdev=net0 \
  -vnc :0 \
  -serial stdio
[jenkins@localhost euler]$
