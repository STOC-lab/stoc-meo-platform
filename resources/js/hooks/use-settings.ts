import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import { useCurrentOrganization } from '@/stores/auth';
import type {
    BillingPlan,
    Brand,
    GbpConnection,
    Member,
    OrganizationInvitation,
    ReportSummary,
    Role,
} from '@/types/api';

export function useBrands() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.brands(organization?.id ?? null),
        enabled: organization !== null,
        queryFn: async (): Promise<Brand[]> => {
            const { data } = await api.get<{ brands: Brand[] }>(`/organizations/${organization!.id}/brands`);

            return data.brands;
        },
    });
}

export function useMembers() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.members(organization?.id ?? null),
        enabled: organization !== null,
        // An administrator's screen; a member without the role gets a 403 and
        // retrying will not change that.
        retry: false,
        queryFn: async (): Promise<Member[]> => {
            const { data } = await api.get<{ members: Member[] }>(`/organizations/${organization!.id}/members`);

            return data.members;
        },
    });
}

/** The invitations that have gone out and not yet been accepted. */
export function useInvitations() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.invitations(organization?.id ?? null),
        enabled: organization !== null,
        retry: false,
        queryFn: async (): Promise<OrganizationInvitation[]> => {
            const { data } = await api.get<{ invitations: OrganizationInvitation[] }>(
                `/organizations/${organization!.id}/invitations`,
            );

            return data.invitations;
        },
    });
}

export function useInviteMember() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ email, role }: { email: string; role: Role }) => {
            const { data } = await api.post(`/organizations/${organization!.id}/invitations`, { email, role });

            return data;
        },
        // The seat the invitation takes counts against the plan, so the member
        // list is refreshed alongside the invitation list.
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: keys.invitations(organization?.id ?? null) });
            queryClient.invalidateQueries({ queryKey: keys.members(organization?.id ?? null) });
        },
    });
}

export function useRevokeInvitation() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (invitationId: number) => {
            await api.delete(`/organizations/${organization!.id}/invitations/${invitationId}`);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.invitations(organization?.id ?? null) }),
    });
}

export function useUpdateMemberRole() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, role }: { id: number; role: Role }) => {
            const { data } = await api.patch(`/organizations/${organization!.id}/members/${id}`, { role });

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.members(organization?.id ?? null) }),
    });
}

export function useRemoveMember() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (memberId: number) => {
            await api.delete(`/organizations/${organization!.id}/members/${memberId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: keys.members(organization?.id ?? null) });
            queryClient.invalidateQueries({ queryKey: keys.invitations(organization?.id ?? null) });
        },
    });
}

export interface NewLocation {
    name: string;
    address?: string | null;
    phone?: string | null;
    website_url?: string | null;
    gbp_location_id?: string | null;
    latitude?: number | null;
    longitude?: number | null;
}

export function useCreateLocation() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (location: NewLocation) => {
            const { data } = await api.post(`/organizations/${organization!.id}/locations`, location);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations(organization?.id ?? null) }),
    });
}

export function useUpdateLocation() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, ...changes }: NewLocation & { id: number }) => {
            const { data } = await api.patch(`/organizations/${organization!.id}/locations/${id}`, changes);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations(organization?.id ?? null) }),
    });
}

/** The store front's Google connection, if it has one. */
export function useGbpConnection(locationId: number | null) {
    return useQuery({
        queryKey: keys.gbpConnection(locationId),
        enabled: locationId !== null,
        queryFn: async (): Promise<GbpConnection | null> => {
            const { data } = await api.get<{ connection: GbpConnection | null }>('/auth/google/connection', {
                params: { location_id: locationId },
            });

            return data.connection;
        },
        // An administrator's screen; a member without the role gets a 403 and
        // there is nothing to show them.
        retry: false,
    });
}

/**
 * Start the Google consent flow. The API hands back the URL rather than
 * redirecting, so the browser leaves only once the state is safely stored.
 */
export function useConnectGoogle() {
    return useMutation({
        mutationFn: async (locationId: number) => {
            const { data } = await api.get<{ redirect_url: string }>('/auth/google/redirect', {
                params: { location_id: locationId },
            });

            return data.redirect_url;
        },
        onSuccess: (url) => {
            window.location.href = url;
        },
    });
}

export function useReports(locationId: number | null) {
    return useQuery({
        queryKey: keys.reports(locationId),
        enabled: locationId !== null,
        retry: false,
        queryFn: async (): Promise<ReportSummary[]> => {
            const { data } = await api.get<{ reports: ReportSummary[] }>(`/locations/${locationId}/reports`);

            return data.reports;
        },
    });
}

/** The plans the organization can move to from the billing tab. */
export function useBillingPlans(enabled: boolean) {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.billingPlans(organization?.id ?? null),
        enabled: enabled && organization !== null,
        // An administrator's screen; a member without the role gets a 403 and
        // retrying will not change that.
        retry: false,
        queryFn: async (): Promise<BillingPlan[]> => {
            const { data } = await api.get<{ plans: BillingPlan[] }>('/billing/plans');

            return data.plans;
        },
    });
}

/**
 * Open a Stripe Checkout session for a plan. The API hands back the URL, and
 * the browser leaves for it; Stripe brings the customer back to the billing tab.
 */
export function useStartCheckout() {
    return useMutation({
        mutationFn: async (planCode: string) => {
            const { data } = await api.post<{ url: string }>('/billing/checkout', { plan_code: planCode });

            return data.url;
        },
        onSuccess: (url) => {
            window.location.href = url;
        },
    });
}
