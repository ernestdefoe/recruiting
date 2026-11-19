import Model from 'flarum/common/Model';

/** A single recruit row on the board. */
export interface Recruit {
  id: number | string | null;
  name: string;
  position: string | null;
  height: string | null;
  weight: string | null;
  hometown: string | null;
  country: string | null;
  stars: number | null;
  rating: number | null;
  ranking: number | null;
  status: 'committed' | 'undecided';
  school: string | null;
  highSchool: string | null;
  recruitType: string | null;
  photoUrl: string | null;
}

/** The forum's recruiting board: GET /api/recruiting-boards/1. */
export default class RecruitingBoard extends Model {
  year = Model.attribute<number>('year');
  recruits = Model.attribute<Recruit[]>('recruits');
  error = Model.attribute<string | null>('error');
}
