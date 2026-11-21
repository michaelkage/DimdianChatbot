-- Seed for the public GitHub Pages mock site only.
-- Add any custom domain as a separate verified origin after confirming ownership.
INSERT INTO public.chatbridge_tenants (id, external_tenant_id, display_name, status)
VALUES (
  '00000000-0000-4000-8000-000000000001',
  '00000000-0000-4000-8000-000000000001',
  'Dimdian Mock Site',
  'active'
)
ON CONFLICT (id) DO UPDATE SET
  display_name = EXCLUDED.display_name,
  status = 'active',
  updated_at = now();

INSERT INTO public.chatbridge_site_origins (origin, tenant_id, status, verified_at)
VALUES (
  'https://michaelkage.github.io',
  '00000000-0000-4000-8000-000000000001',
  'verified',
  now()
)
ON CONFLICT (origin) DO UPDATE SET
  tenant_id = EXCLUDED.tenant_id,
  status = 'verified',
  verified_at = now();
