# Triển khai Backend lên VPS Ubuntu 24.04

## Cấu trúc trên VPS
```
/var/www/tad-attend/
├── current -> /var/www/tad-attend/releases/<ts>-<sha>/backend
├── incoming/              # deploy user tải release-*.tar.gz vào đây
├── releases/              # giữ 5 bản gần nhất để rollback nhanh
└── shared/
    ├── .env               # chmod 640 root:www-data (không bao giờ commit)
    └── storage/           # chown www-data:www-data (ảnh minh chứng private + logs)
```

## Auto-deploy bằng GitHub Actions
Workflow `.github/workflows/backend-deploy.yml` chạy tự động sau khi `Backend CI` trên nhánh `main` xanh (hoặc chạy tay bằng `workflow_dispatch`).

### GitHub Repository Secrets bắt buộc
- `VPS_HOST`: địa chỉ máy chủ (IP hoặc domain)
- `VPS_USER`: tài khoản SSH chuyên dùng cho deploy (ví dụ `deploy`)
- `VPS_SSH_KEY`: private key SSH (ed25519) của tài khoản `deploy`
- `VPS_PORT`: cổng SSH (tùy chọn, mặc định `22`)

### Phân quyền tối thiểu trên VPS
- Cài `deploy/tad-attend-deploy.sh` vào `/usr/local/sbin/tad-attend-deploy.sh` (`chmod 750 root:root`).
- Cấp đúng 1 dòng trong `/etc/sudoers.d/tad-attend-deploy`:
  ```
  deploy ALL=(root) NOPASSWD: /usr/local/sbin/tad-attend-deploy.sh *
  ```
- Không cấp quyền `root` SSH cho CI.

## Rollback nhanh
Chỉ cần đổi symlink `current` về bản cũ trong `/var/www/tad-attend/releases/` rồi `systemctl reload php8.3-fpm`.
